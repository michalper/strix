<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\PaymentWebhookPayload;
use App\Exception\InvalidWebhookPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookPayloadTest extends TestCase
{
    public function testValidPayloadIsParsed(): void
    {
        $payload = PaymentWebhookPayload::fromRawJson(self::validPayloadJson());

        self::assertSame('evt_9f8d7c6b5a4321', $payload->eventId);
        self::assertSame('charge.successful', $payload->eventType);
        self::assertTrue($payload->livemode);
        self::assertSame('txn_8a7b6c5d4e3f', $payload->transactionId);
        self::assertSame('PAYU-9876543210', $payload->providerReference);
        self::assertSame(29990, $payload->amount->minorUnits);
        self::assertSame('PLN', $payload->amount->currency);
        self::assertSame('blik', $payload->paymentMethodType);
        self::assertSame('mBank', $payload->paymentMethodIssuer);
        self::assertSame('COMPLETED', $payload->transactionStatus);
        self::assertSame('cus_11223344', $payload->customerId);
        self::assertSame('jan.kowalski@example.com', $payload->customerEmail);
        self::assertSame('ORD-2024-8839', $payload->orderId);
        self::assertSame('store_main_pl', $payload->storeId);
        self::assertSame('mobile_app', $payload->channel);
    }

    public function testMalformedJsonIsRejectedAsMalformedJson(): void
    {
        try {
            PaymentWebhookPayload::fromRawJson('{not valid json');
            self::fail('Expected InvalidWebhookPayloadException to be thrown.');
        } catch (InvalidWebhookPayloadException $e) {
            self::assertSame('malformed_json', $e->getErrorCode());
        }
    }

    public function testNonObjectJsonIsRejectedAsMalformedJson(): void
    {
        try {
            PaymentWebhookPayload::fromRawJson('[1, 2, 3]');
            self::fail('Expected InvalidWebhookPayloadException to be thrown.');
        } catch (InvalidWebhookPayloadException $e) {
            self::assertSame('malformed_json', $e->getErrorCode());
        }
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('missingOrInvalidFieldProvider')]
    public function testMissingOrInvalidFieldIsRejected(callable $mutate, string $expectedField): void
    {
        $data = $mutate(self::validPayloadArray());

        try {
            PaymentWebhookPayload::fromRawJson(json_encode($data, JSON_THROW_ON_ERROR));
            self::fail('Expected InvalidWebhookPayloadException to be thrown.');
        } catch (InvalidWebhookPayloadException $e) {
            self::assertSame('invalid_payload', $e->getErrorCode());
            self::assertContains($expectedField, $e->getFieldErrors());
        }
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function missingOrInvalidFieldProvider(): iterable
    {
        yield 'missing event_id' => [
            static function (array $data): array {
                unset($data['event_id']);

                return $data;
            },
            'event_id',
        ];
        yield 'missing data.transaction.id' => [
            static function (array $data): array {
                unset($data['data']['transaction']['id']);

                return $data;
            },
            'data.transaction.id',
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
        yield 'missing data.metadata.store_id' => [
            static function (array $data): array {
                unset($data['data']['metadata']['store_id']);

                return $data;
            },
            'data.metadata.store_id',
        ];
        yield 'amount.value as non-numeric string' => [
            static function (array $data): array {
                $data['data']['transaction']['amount']['value'] = 'abc';

                return $data;
            },
            'data.transaction.amount.value',
        ];
        yield 'amount.value is zero' => [
            static function (array $data): array {
                $data['data']['transaction']['amount']['value'] = 0;

                return $data;
            },
            'data.transaction.amount.value',
        ];
        yield 'amount.value is negative' => [
            static function (array $data): array {
                $data['data']['transaction']['amount']['value'] = -100;

                return $data;
            },
            'data.transaction.amount.value',
        ];
        yield 'amount.currency has unknown format' => [
            static function (array $data): array {
                $data['data']['transaction']['amount']['currency'] = 'XX';

                return $data;
            },
            'data.transaction.amount.currency',
        ];
        yield 'livemode as string instead of bool' => [
            static function (array $data): array {
                $data['livemode'] = 'true';

                return $data;
            },
            'livemode',
        ];
        yield 'timestamp as non-numeric string' => [
            static function (array $data): array {
                $data['timestamp'] = 'not-a-number';

                return $data;
            },
            'timestamp',
        ];
        yield 'data is not an object' => [
            static function (array $data): array {
                $data['data'] = 'not-an-object';

                return $data;
            },
            'data',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validPayloadArray(): array
    {
        return [
            'event' => 'charge.successful',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_8a7b6c5d4e3f',
                    'provider_reference' => 'PAYU-9876543210',
                    'amount' => [
                        'value' => 29990,
                        'currency' => 'PLN',
                    ],
                    'payment_method' => [
                        'type' => 'blik',
                        'issuer' => 'mBank',
                    ],
                    'status' => 'COMPLETED',
                ],
                'customer' => [
                    'id' => 'cus_11223344',
                    'email' => 'jan.kowalski@example.com',
                    'billing_address' => [
                        'first_name' => 'Jan',
                        'last_name' => 'Kowalski',
                        'company' => 'Kowalski Dev Ltd',
                        'country' => 'PL',
                    ],
                ],
                'metadata' => [
                    'order_id' => 'ORD-2024-8839',
                    'store_id' => 'store_main_pl',
                    'channel' => 'mobile_app',
                ],
            ],
        ];
    }

    private static function validPayloadJson(): string
    {
        return json_encode(self::validPayloadArray(), JSON_THROW_ON_ERROR);
    }
}
