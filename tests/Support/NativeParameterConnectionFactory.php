<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connectors\ConnectionFactory;

final class NativeParameterConnectionFactory extends ConnectionFactory
{
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        return $driver === 'pgsql'
            ? new NativeParameterSelectConnection($connection, $database, $prefix, $config)
            : parent::createConnection($driver, $connection, $database, $prefix, $config);
    }
}
