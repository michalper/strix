<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits <= 0) {
            throw new \InvalidArgumentException('Money amount must be a positive integer expressed in minor units.');
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\sprintf('Currency must be a 3-letter uppercase ISO 4217 code, got "%s".', $currency));
        }
    }
}
