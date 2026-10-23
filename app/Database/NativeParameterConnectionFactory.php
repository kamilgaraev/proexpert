<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;

final class NativeParameterConnectionFactory extends ConnectionFactory
{
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        if ($driver !== 'pgsql' || PHP_VERSION_ID < 80500 || ($config['native_select_parameters'] ?? false) !== true || Connection::getResolver($driver) !== null) {
            return parent::createConnection($driver, $connection, $database, $prefix, $config);
        }

        return new NativeParameterPostgresConnection($connection, $database, $prefix, $config);
    }
}
