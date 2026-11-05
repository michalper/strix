<?php

declare(strict_types=1);

/**
 * Minimal stand-in for the real sales-notification API. No framework on purpose —
 * this exists only to give the worker something deterministic to call in
 * docker-compose and in E2E tests, not to demonstrate anything about the task itself.
 *
 * POST /notify           records the call (NDJSON) and fails deterministically based
 *                         on a marker substring in order_id, so tests can exercise
 *                         retry/backoff/DLQ paths without real network flakiness.
 * GET  /__requests        lets tests inspect what the worker actually sent.
 */

$dataDir = '/data';
$logFile = $dataDir.'/requests.ndjson';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

/**
 * @return list<array<string, mixed>>
 */
function readRecords(string $logFile): array
{
    if (!is_file($logFile)) {
        return [];
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        return [];
    }

    $records = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true);
        if (is_array($record)) {
            $records[] = $record;
        }
    }

    return $records;
}

if ('GET' === $method && '/__requests' === $path) {
    $orderId = $_GET['order_id'] ?? null;
    $records = readRecords($logFile);

    if (null !== $orderId) {
        $records = array_values(array_filter(
            $records,
            static fn (array $record): bool => ($record['order_id'] ?? null) === $orderId,
        ));
    }

    header('Content-Type: application/json');
    echo json_encode(['requests' => $records], JSON_THROW_ON_ERROR);

    return;
}

if ('POST' === $method && '/notify' === $path) {
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);
    $orderId = is_array($payload) ? (string) ($payload['order_id'] ?? '') : '';
    $eventId = is_array($payload) ? (string) ($payload['event_id'] ?? '') : '';

    $priorAttempts = 0;
    foreach (readRecords($logFile) as $existing) {
        if (($existing['event_id'] ?? null) === $eventId) {
            ++$priorAttempts;
        }
    }

    $status = 200;
    if (str_contains($orderId, 'TIMEOUT')) {
        sleep(10);
        $status = 504;
    } elseif (str_contains($orderId, 'FAIL')) {
        $status = 500;
    } elseif (str_contains($orderId, 'RETRY_ONCE') && 0 === $priorAttempts) {
        $status = 500;
    }

    error_log(sprintf(
        '[notification-mock] POST /notify order_id=%s event_id=%s attempt=%d status=%d',
        $orderId,
        $eventId,
        $priorAttempts + 1,
        $status,
    ));

    $record = [
        'received_at' => date('c'),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'body' => $payload,
        'response_status' => $status,
    ];
    file_put_contents($logFile, json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['status' => $status >= 500 ? 'error' : 'ok'], JSON_THROW_ON_ERROR);

    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
