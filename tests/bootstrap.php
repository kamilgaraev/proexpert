<?php

declare(strict_types=1);

use Tests\Support\IsolatedPostgresTestDatabase;

require dirname(__DIR__).'/vendor/autoload.php';

$rootDatabase = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
$configuration = IsolatedPostgresTestDatabase::databaseConfiguration();
$database = (string) $configuration['database'];

putenv('DB_DATABASE='.$database);
putenv('DB_SCHEMA=public');
putenv('PHPUNIT_ROOT_DB_DATABASE='.$rootDatabase);
$_ENV['DB_DATABASE'] = $database;
$_ENV['DB_SCHEMA'] = 'public';
$_ENV['PHPUNIT_ROOT_DB_DATABASE'] = $rootDatabase;
$_SERVER['DB_DATABASE'] = $database;
$_SERVER['DB_SCHEMA'] = 'public';
$_SERVER['PHPUNIT_ROOT_DB_DATABASE'] = $rootDatabase;

$connection = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string) $configuration['host'],
        (string) $configuration['port'],
        $database,
    ),
    (string) $configuration['username'],
    (string) $configuration['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$connection->exec('CREATE EXTENSION IF NOT EXISTS vector');
$connection->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
