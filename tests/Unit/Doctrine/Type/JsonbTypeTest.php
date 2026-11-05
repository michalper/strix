<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine\Type;

use App\Doctrine\Type\JsonbType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class JsonbTypeTest extends TestCase
{
    public function testSqlDeclarationIsJsonb(): void
    {
        self::assertSame('JSONB', (new JsonbType())->getSQLDeclaration([], new PostgreSQLPlatform()));
    }

    public function testNameIsJsonb(): void
    {
        self::assertSame('jsonb', (new JsonbType())->getName());
    }
}
