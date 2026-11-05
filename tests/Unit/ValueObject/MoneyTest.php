<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testValidMoneyIsConstructed(): void
    {
        $money = new Money(29990, 'PLN');

        self::assertSame(29990, $money->minorUnits);
        self::assertSame('PLN', $money->currency);
    }

    #[DataProvider('invalidAmountProvider')]
    public function testInvalidAmountIsRejected(int $minorUnits, string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Money($minorUnits, $currency);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function invalidAmountProvider(): iterable
    {
        yield 'zero amount' => [0, 'PLN'];
        yield 'negative amount' => [-100, 'PLN'];
        yield 'lowercase currency' => [100, 'pln'];
        yield 'too short currency' => [100, 'PL'];
        yield 'too long currency' => [100, 'PLNN'];
        yield 'numeric currency' => [100, '123'];
    }
}
