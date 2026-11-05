<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Same behaviour as Doctrine's built-in "json" type, but declares the column as
 * PostgreSQL's JSONB (indexable, slightly more efficient storage) instead of JSON,
 * so doctrine:schema:validate stays in sync with the migration's actual column type.
 */
final class JsonbType extends JsonType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    public function getName(): string
    {
        return 'jsonb';
    }
}
