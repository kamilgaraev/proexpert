<?php

declare(strict_types=1);

namespace App\Support\Database;

use InvalidArgumentException;

final class PostgresSchemaIdentifier
{
    public static function quote(string $identifier): string
    {
        if ($identifier === '' || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidArgumentException('PostgreSQL schema identifier is invalid.');
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
