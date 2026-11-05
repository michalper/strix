<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if (!self::isValidAmount($minorUnits)) {
            throw new \InvalidArgumentException('Money amount must be a positive integer expressed in minor units.');
        }

        if (!self::isValidCurrencyCode($currency)) {
            throw new \InvalidArgumentException(\sprintf('Currency must be a 3-letter uppercase ISO 4217 code, got "%s".', $currency));
        }
    }

    /**
     * Exposed so callers that need a field-level reason for a validation failure
     * (e.g. PaymentWebhookPayload attributing one of two possible invalid-amount
     * fields) can ask without duplicating the rule itself.
     */
    public static function isValidAmount(int $minorUnits): bool
    {
        return $minorUnits > 0;
    }

    public static function isValidCurrencyCode(string $currency): bool
    {
        return 1 === preg_match('/^[A-Z]{3}$/', $currency);
    }
}
