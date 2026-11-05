<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @internal
 *
 * Runs against the LIVE docker-compose stack (real postgres/rabbitmq/worker/nginx/
 * mock containers), not the Symfony test kernel. Intended to run via
 * `docker compose exec -T e2e-runner vendor/bin/phpunit --testsuite=e2e` with
 * `docker-compose.override.e2e.yml` applied (fast worker retries).
 *
 * @coversNothing
 */
#[Group('e2e')]
final class FullPipelineE2ETest extends TestCase
{
    private const SECRET = 'ChangeMeInEnvLocal'; // matches .env WEBHOOK_HMAC_SECRET
    private const ENDPOINT = 'http://nginx/api/v1/webhooks/payment';
    private const MOCK_REQUESTS_URL = 'http://notification-mock:8080/__requests';
    private const POLL_TIMEOUT_SECONDS = 20;

    private HttpClientInterface $http;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->http = HttpClient::create();
        $this->pdo = new \PDO('pgsql:host=postgres;port=5432;dbname=strix', 'strix', 'strix');
    }

    public function testHappyPathEndToEnd(): void
    {
        $eventId = 'evt_e2e_'.bin2hex(random_bytes(4));
        $orderId = 'ORD-E2E-HAPPY-'.bin2hex(random_bytes(4));

        $this->postWebhook($eventId, $orderId);

        self::assertSame('NOTIFIED', $this->pollStatus($eventId));

        $requests = $this->mockRequestsFor($orderId);
        self::assertCount(1, $requests);
    }

    public function testPermanentFailureEndsInDeadLetterQueue(): void
    {
        $eventId = 'evt_e2e_'.bin2hex(random_bytes(4));
        $orderId = 'ORD-FAIL-E2E-'.bin2hex(random_bytes(4));

        $this->postWebhook($eventId, $orderId);

        self::assertSame('DEAD_LETTER', $this->pollStatus($eventId));

        $statement = $this->pdo->query("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        self::assertNotFalse($statement);
        self::assertGreaterThanOrEqual(1, (int) $statement->fetchColumn());
    }

    /**
     * Two real HTTP requests to the live stack, dispatched before either is
     * resolved (Symfony HttpClient's responses are lazy - calling getStatusCode()
     * is what blocks, so issuing both request() calls first means they are
     * genuinely in flight concurrently), reproducing "two identical webhooks in
     * the same millisecond" over the actual network stack, not just at the DB.
     */
    public function testConcurrentHttpDuplicateRequestsResultInExactlyOneRow(): void
    {
        $eventId = 'evt_e2e_'.bin2hex(random_bytes(4));
        $body = $this->payload($eventId, 'ORD-E2E-CONCURRENT-'.bin2hex(random_bytes(4)));
        $options = [
            'body' => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $body, self::SECRET)],
        ];

        $responseA = $this->http->request('POST', self::ENDPOINT, $options);
        $responseB = $this->http->request('POST', self::ENDPOINT, $options);

        $statuses = [$responseA->getStatusCode(), $responseB->getStatusCode()];
        sort($statuses);
        self::assertSame([200, 202], $statuses, 'Expected exactly one 202 (accepted) and one 200 (already_processed).');

        self::assertSame('NOTIFIED', $this->pollStatus($eventId));
        self::assertSame(1, $this->countRows($eventId));
    }

    private function countRows(string $eventId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE event_id = ?');
        $statement->execute([$eventId]);

        return (int) $statement->fetchColumn();
    }

    private function postWebhook(string $eventId, string $orderId): void
    {
        $body = $this->payload($eventId, $orderId);
        $response = $this->http->request('POST', self::ENDPOINT, [
            'body' => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $body, self::SECRET)],
        ]);

        self::assertSame(202, $response->getStatusCode());
    }

    private function pollStatus(string $eventId): ?string
    {
        $statement = $this->pdo->prepare('SELECT processing_status FROM webhook_events WHERE event_id = ?');
        $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;

        do {
            $statement->execute([$eventId]);
            $status = $statement->fetchColumn();
            if (\in_array($status, ['NOTIFIED', 'DEAD_LETTER'], true)) {
                return $status;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mockRequestsFor(string $orderId): array
    {
        $response = $this->http->request('GET', self::MOCK_REQUESTS_URL, ['query' => ['order_id' => $orderId]]);

        /** @var array{requests: list<array<string, mixed>>} $data */
        $data = $response->toArray();

        return $data['requests'];
    }

    private function payload(string $eventId, string $orderId): string
    {
        return json_encode([
            'event' => 'charge.successful',
            'event_id' => $eventId,
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_'.$eventId,
                    'amount' => ['value' => 1000, 'currency' => 'PLN'],
                    'status' => 'COMPLETED',
                ],
                'customer' => ['id' => 'cus_1', 'email' => 'e2e@example.com'],
                'metadata' => ['order_id' => $orderId, 'store_id' => 'store_1'],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
