<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;

final class NativeParameterSourceCaller
{
    public static function read(Connection $connection): int
    {
        return $connection->selectOne('SELECT CAST(? AS integer) AS value', [41])->value;
    }
}
