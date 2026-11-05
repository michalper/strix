<?php

declare(strict_types=1);

/**
 * Standalone helper used only by PaymentWebhookControllerTest's race-condition test.
 *
 * Inserts one row for the given event_id via its own PDO connection/OS process, so
 * two instances started at the same time genuinely race at the database level
 * instead of being serialized by a single PHP process. A plain sequential
 * "two DBAL connections, same process" attempt would deadlock: Postgres makes the
 * second INSERT block until the first transaction commits or rolls back, and
 * nothing else in a single-threaded script would ever issue that commit.
 */
$eventId = $argv[1] ?? null;
if (null === $eventId) {
    fwrite(STDERR, "Usage: concurrent-insert.php <event_id>\n");

    exit(2);
}

$pdo = new PDO('pgsql:host=postgres;port=5432;dbname=strix_test', 'strix', 'strix');

try {
    $statement = $pdo->prepare(
        'INSERT INTO webhook_events
            (event_id, event_type, livemode, occurred_at, transaction_id, amount_value,
             amount_currency, transaction_status, customer_id, customer_email, order_id,
             store_id, raw_payload, processing_status, retry_count, received_at)
         VALUES
            (:event_id, \'charge.successful\', true, now(), \'txn_race\', 100,
             \'PLN\', \'COMPLETED\', \'cus_race\', \'race@example.com\', \'ORD-RACE\',
             \'store_1\', \'{}\', \'PENDING\', 0, now())',
    );
    $statement->execute(['event_id' => $eventId]);

    exit(0);
} catch (PDOException $e) {
    exit('23505' === $e->getCode() ? 1 : 3); // 23505 = unique_violation
}
