<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$connection = [
    'driver' => getenv('DB_CONNECTION') ?: '',
    'host' => getenv('DB_HOST') ?: '',
    'port' => getenv('DB_PORT') ?: '',
    'database' => getenv('DB_DATABASE') ?: '',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
];
$database = new Manager;
$database->addConnection($connection);
$database->setAsGlobal();
$database->bootEloquent();
Facade::setFacadeApplication($database->getContainer());
Container::setInstance($database->getContainer());
if (! class_exists('DB', false)) {
    class_alias(\Illuminate\Support\Facades\DB::class, 'DB');
}
$database->getContainer()->instance('db', $database->getDatabaseManager());
$database->getContainer()->instance('db.schema', $database->getConnection()->getSchemaBuilder());
$database->getContainer()->instance('log', new NullLogger);
$database->getContainer()->instance('config', new Repository([
    'database.default' => 'default',
    'database.connections.default' => $connection,
]));

try {
    EstimateGenerationContractDatabaseProvisioner::provision($database->getConnection(), $root, $argv[1] ?? '');
    fwrite(STDOUT, "estimate generation contract provisioned\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
}
