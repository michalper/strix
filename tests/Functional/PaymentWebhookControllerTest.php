<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookEvent;
use App\Repository\WebhookEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * @internal
 *
 * @coversNothing
 */
final class PaymentWebhookControllerTest extends WebTestCase
{
    private const SECRET = 'test-secret'; // matches .env.test WEBHOOK_HMAC_SECRET
    private const ENDPOINT = '/api/v1/webhooks/payment';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // createClient() boots the kernel; calling bootKernel() separately first is
        // explicitly unsupported by WebTestCase, so this is the one boot per test.
        $this->client = self::createClient();
        // By default KernelBrowser reboots (fresh container) before every request,
        // which would silently reset the in-memory Messenger transport between the
        // two sequential requests the idempotency test makes - disable that so
        // assertions about "exactly one message ever sent" are meaningful.
        $this->client->disableReboot();

        // The test DB is a real, persistent Postgres - start every test from a clean
        // table rather than relying on test ordering or unique fixture data alone.
        $this->connection()->executeStatement('TRUNCATE TABLE webhook_events');
    }

    public function testValidSignedPayloadIsPersistedAndAccepted(): void
    {
        $body = $this->validPayload('evt_func_1', 'ORD-FUNC-1');

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $responseData = $this->decodeResponse($this->client->getResponse());
        self::assertSame('accepted', $responseData['status']);
        self::assertSame('evt_func_1', $responseData['event_id']);

        self::assertSame(1, $this->countRowsForEventId('evt_func_1'));
        self::assertCount(1, $this->asyncTransport()->getSent());
    }

    public function testChargeSuccessfulIsProcessedWithNoPriorPaymentIntentRecord(): void
    {
        // Simulates out-of-order delivery: there is deliberately no earlier
        // "payment intent" row for this transaction_id anywhere in the table - the
        // system has no such concept, so a charge.successful event is processed as
        // a self-contained fact and must succeed regardless of what, if anything,
        // came before it for the same transaction.
        $transactionId = 'txn_ooo_'.bin2hex(random_bytes(4));
        self::assertSame(0, $this->countRowsForTransactionId($transactionId));

        $data = $this->validPayloadArray('evt_ooo', 'ORD-OOO');
        $data['data']['transaction']['id'] = $transactionId;
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame(1, $this->countRowsForTransactionId($transactionId));
    }

    public function testDuplicateEventIdIsIdempotentUnderSequentialRequests(): void
    {
        $body = $this->validPayload('evt_func_dup', 'ORD-FUNC-DUP');
        $headers = $this->signedHeaders($body);

        $this->client->request('POST', self::ENDPOINT, server: $headers, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        // Messenger's in-memory transport is reset after each test-client request
        // (even with reboot disabled), so this has to be checked before the second
        // request, not accumulated across both.
        self::assertCount(1, $this->asyncTransport()->getSent());

        $this->client->request('POST', self::ENDPOINT, server: $headers, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('already_processed', $this->decodeResponse($this->client->getResponse())['status']);
        // The duplicate must not dispatch a second notification.
        self::assertCount(0, $this->asyncTransport()->getSent());

        self::assertSame(1, $this->countRowsForEventId('evt_func_dup'));
    }

    /**
     * Two raw PDO connections in separate OS processes, started without waiting for
     * either to finish, racing a real INSERT of the same event_id against the test
     * database - a faithful simulation of "two identical webhooks in the same
     * millisecond" that a single-process, same-thread test cannot produce (Postgres
     * would simply block the second INSERT until the first transaction resolves,
     * which never happens if nothing else in that one process can trigger it).
     */
    public function testConcurrentDuplicateRequestsRaceConditionAtDatabaseLevel(): void
    {
        $eventId = 'evt_race_'.bin2hex(random_bytes(4));
        $script = __DIR__.'/../Support/concurrent-insert.php';

        $processA = new Process([PHP_BINARY, $script, $eventId]);
        $processB = new Process([PHP_BINARY, $script, $eventId]);

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $exitCodes = [$processA->getExitCode(), $processB->getExitCode()];
        sort($exitCodes);

        self::assertSame(
            [0, 1],
            $exitCodes,
            \sprintf(
                "Exactly one concurrent insert should succeed (exit 0) and the other should fail with a unique violation (exit 1).\nA stderr: %s\nB stderr: %s",
                $processA->getErrorOutput(),
                $processB->getErrorOutput(),
            ),
        );

        self::assertSame(1, $this->countRowsForEventId($eventId));
    }

    public function testMissingSignatureHeaderReturns401(): void
    {
        $body = $this->validPayload('evt_no_sig', 'ORD-NO-SIG');

        $this->client->request('POST', self::ENDPOINT, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, $this->countRowsForEventId('evt_no_sig'));
    }

    public function testInvalidSignatureReturns401(): void
    {
        $body = $this->validPayload('evt_bad_sig', 'ORD-BAD-SIG');

        $this->client->request('POST', self::ENDPOINT, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => 'not-a-valid-signature',
        ], content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMalformedJsonReturns400WithReadableError(): void
    {
        $body = '{not valid json';

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('malformed_json', $this->decodeResponse($this->client->getResponse())['error']);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('provideMissingOrInvalidFieldReturns400Cases')]
    public function testMissingOrInvalidFieldReturns400(callable $mutate, string $expectedField): void
    {
        $data = $mutate($this->validPayloadArray('evt_invalid', 'ORD-INVALID'));
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $responseData = $this->decodeResponse($this->client->getResponse());
        self::assertSame('invalid_payload', $responseData['error']);
        self::assertContains($expectedField, $responseData['fields']);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function provideMissingOrInvalidFieldReturns400Cases(): iterable
    {
        yield 'missing event_id' => [
            static function (array $data): array {
                unset($data['event_id']);

                return $data;
            },
            'event_id',
        ];

        yield 'missing data.customer.email' => [
            static function (array $data): array {
                unset($data['data']['customer']['email']);

                return $data;
            },
            'data.customer.email',
        ];

        yield 'missing data.metadata.order_id' => [
            static function (array $data): array {
                unset($data['data']['metadata']['order_id']);

                return $data;
            },
            'data.metadata.order_id',
        ];

        yield 'amount.value as non-numeric string' => [
            static function (array $data): array {
                $data['data']['transaction']['amount']['value'] = 'abc';

                return $data;
            },
            'data.transaction.amount.value',
        ];

        yield 'livemode as string instead of bool' => [
            static function (array $data): array {
                $data['livemode'] = 'true';

                return $data;
            },
            'livemode',
        ];
    }

    public function testNegativeOrZeroAmountReturns400(): void
    {
        $data = $this->validPayloadArray('evt_zero_amount', 'ORD-ZERO');
        $data['data']['transaction']['amount']['value'] = 0;
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUnknownCurrencyFormatReturns400(): void
    {
        $data = $this->validPayloadArray('evt_bad_currency', 'ORD-CURRENCY');
        $data['data']['transaction']['amount']['currency'] = 'XX';
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUnexpectedExceptionNeverLeaksStackTraceReturns500AsJson(): void
    {
        $brokenRepository = new class extends WebhookEventRepository {
            public function __construct() {}

            public function insertIfNotExists(WebhookEvent $event): bool
            {
                throw new \RuntimeException('Database exploded.');
            }
        };
        self::getContainer()->set(WebhookEventRepository::class, $brokenRepository);

        $body = $this->validPayload('evt_boom', 'ORD-BOOM');
        $this->client->request('POST', self::ENDPOINT, server: $this->signedHeaders($body), content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $rawContent = (string) $this->client->getResponse()->getContent();
        self::assertSame(['error' => 'internal_error'], json_decode($rawContent, true));
        self::assertStringNotContainsString('Database exploded', $rawContent);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function countRowsForEventId(string $eventId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM webhook_events WHERE event_id = ?', [$eventId]);
    }

    private function countRowsForTransactionId(string $transactionId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM webhook_events WHERE transaction_id = ?', [$transactionId]);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $body): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ];
    }

    private function validPayload(string $eventId, string $orderId): string
    {
        return json_encode($this->validPayloadArray($eventId, $orderId), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayloadArray(string $eventId, string $orderId): array
    {
        return [
            'event' => 'charge.successful',
            'event_id' => $eventId,
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_'.$eventId,
                    'provider_reference' => 'PAYU-1',
                    'amount' => ['value' => 1000, 'currency' => 'PLN'],
                    'payment_method' => ['type' => 'blik', 'issuer' => 'mBank'],
                    'status' => 'COMPLETED',
                ],
                'customer' => [
                    'id' => 'cus_1',
                    'email' => 'test@example.com',
                ],
                'metadata' => [
                    'order_id' => $orderId,
                    'store_id' => 'store_1',
                ],
            ],
        ];
    }
}
