<?php

declare(strict_types=1);

namespace App\Dto;

use App\Exception\InvalidWebhookPayloadException;
use App\ValueObject\Money;

/**
 * Flat mapping of the provider's webhook JSON into typed fields actually used by the
 * domain. Fields never read by the application (e.g. billing_address) are intentionally
 * left out of this DTO — they still reach storage via $rawPayload for audit purposes.
 */
final readonly class PaymentWebhookPayload
{
    private function __construct(
        public string $eventId,
        public string $eventType,
        public bool $livemode,
        public \DateTimeImmutable $occurredAt,
        public string $transactionId,
        public ?string $providerReference,
        public Money $amount,
        public ?string $paymentMethodType,
        public ?string $paymentMethodIssuer,
        public string $transactionStatus,
        public string $customerId,
        public string $customerEmail,
        public string $orderId,
        public string $storeId,
        public ?string $channel,
        /** @var array<string, mixed> */
        public array $rawPayload,
    ) {}

    public static function fromRawJson(string $rawJson): self
    {
        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidWebhookPayloadException::malformedJson('Request body is not valid JSON: '.$e->getMessage());
        }

        if (!\is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            throw InvalidWebhookPayloadException::malformedJson('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        $errors = [];

        $eventType = self::requireString($decoded, 'event', $errors);
        $eventId = self::requireString($decoded, 'event_id', $errors);
        $timestamp = self::requireInt($decoded, 'timestamp', $errors);
        $livemode = self::requireBool($decoded, 'livemode', $errors);

        $data = self::requireArray($decoded, 'data', $errors);
        $transaction = self::requireArray($data, 'transaction', $errors, 'data.transaction');
        $customer = self::requireArray($data, 'customer', $errors, 'data.customer');
        $metadata = self::requireArray($data, 'metadata', $errors, 'data.metadata');

        $transactionId = self::requireString($transaction, 'id', $errors, 'data.transaction.id');
        $transactionStatus = self::requireString($transaction, 'status', $errors, 'data.transaction.status');
        $providerReference = self::optionalString($transaction, 'provider_reference');

        $amountData = self::requireArray($transaction, 'amount', $errors, 'data.transaction.amount');
        $amountValue = self::requireInt($amountData, 'value', $errors, 'data.transaction.amount.value');
        $amountCurrency = self::requireString($amountData, 'currency', $errors, 'data.transaction.amount.currency');

        // Money is the single owner of "positive value" / "3-letter currency code" -
        // try constructing it rather than re-deriving its own invariants here.
        $amount = null;
        if (null !== $amountValue && null !== $amountCurrency) {
            try {
                $amount = new Money($amountValue, $amountCurrency);
            } catch (\InvalidArgumentException) {
                $errors[] = 'data.transaction.amount.value';
                $errors[] = 'data.transaction.amount.currency';
            }
        }

        $paymentMethodData = $transaction['payment_method'] ?? null;
        $paymentMethodType = null;
        $paymentMethodIssuer = null;
        if (\is_array($paymentMethodData)) {
            /** @var array<string, mixed> $paymentMethodData */
            $paymentMethodType = self::optionalString($paymentMethodData, 'type');
            $paymentMethodIssuer = self::optionalString($paymentMethodData, 'issuer');
        }

        $customerId = self::requireString($customer, 'id', $errors, 'data.customer.id');
        $customerEmail = self::requireString($customer, 'email', $errors, 'data.customer.email');

        $orderId = self::requireString($metadata, 'order_id', $errors, 'data.metadata.order_id');
        $storeId = self::requireString($metadata, 'store_id', $errors, 'data.metadata.store_id');
        $channel = self::optionalString($metadata, 'channel');

        if ([] !== $errors) {
            throw InvalidWebhookPayloadException::invalidFields(array_values(array_unique($errors)));
        }

        \assert(!\in_array(null, [$eventType, $eventId, $timestamp, $livemode], true));
        \assert(null !== $transactionId && null !== $transactionStatus);
        \assert(null !== $amountValue && null !== $amountCurrency);
        \assert(null !== $customerId && null !== $customerEmail);
        \assert(null !== $orderId && null !== $storeId);

        return new self(
            eventId: $eventId,
            eventType: $eventType,
            livemode: $livemode,
            occurredAt: new \DateTimeImmutable('@'.$timestamp),
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: new Money($amountValue, $amountCurrency),
            paymentMethodType: $paymentMethodType,
            paymentMethodIssuer: $paymentMethodIssuer,
            transactionStatus: $transactionStatus,
            customerId: $customerId,
            customerEmail: $customerEmail,
            orderId: $orderId,
            storeId: $storeId,
            channel: $channel,
            rawPayload: $decoded,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function requireString(array $data, string $key, array &$errors, ?string $errorField = null): ?string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            $errors[] = $errorField ?? $key;

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function requireInt(array $data, string $key, array &$errors, ?string $errorField = null): ?int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            $errors[] = $errorField ?? $key;

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function requireBool(array $data, string $key, array &$errors, ?string $errorField = null): ?bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            $errors[] = $errorField ?? $key;

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     *
     * @return array<string, mixed>
     */
    private static function requireArray(array $data, string $key, array &$errors, ?string $errorField = null): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            $errors[] = $errorField ?? $key;

            return [];
        }

        // @var array<string, mixed> $value
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
