<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final class IsolatedPostgresTestDatabase
{
    private const SCHEMA_PREFIX = 'most_phpunit_';

    private const DATABASE_PREFIX = 'most_phpunit_';

    private const DATABASE_SUFFIX = '_testing';

    private static ?Capsule $bootstrap = null;

    /** @var list<array{database: string, schema: string}> */
    private static array $schemas = [];

    /** @var list<string> */
    private static array $databases = [];

    /** @var array<string, Capsule> */
    private static array $schemaBootstraps = [];

    private static bool $cleanupRegistered = false;

    /** @return array<string, mixed> */
    public static function configuration(): array
    {
        $configuration = self::baseConfiguration();
        $schema = self::SCHEMA_PREFIX.bin2hex(random_bytes(12));

        self::schemaConnection($configuration)->statement('CREATE SCHEMA "'.$schema.'"');
        self::$schemas[] = [
            'database' => (string) $configuration['database'],
            'schema' => $schema,
        ];
        self::registerCleanup();

        $configuration['search_path'] = $schema;
        $configuration['schema'] = $schema;

        return $configuration;
    }

    public static function connection(): Connection
    {
        $capsule = new Capsule;
        $capsule->addConnection(self::configuration());

        return $capsule->getConnection();
    }

    /** @return array<string, mixed> */
    public static function databaseConfiguration(): array
    {
        $configuration = self::baseConfiguration();
        $database = self::DATABASE_PREFIX.bin2hex(random_bytes(12)).self::DATABASE_SUFFIX;

        self::bootstrapConnection()->statement('CREATE DATABASE "'.$database.'"');
        self::$databases[] = $database;
        self::registerCleanup();

        $configuration['database'] = $database;
        $configuration['search_path'] = 'public';
        $configuration['schema'] = 'public';

        return $configuration;
    }

    /** @return array<string, mixed> */
    private static function baseConfiguration(): array
    {
        $environment = (string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        $driver = (string) ($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: '');
        $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if ($environment !== 'testing' || $driver !== 'pgsql' || preg_match('/_testing$/D', $database) !== 1) {
            throw new RuntimeException('postgres_test_database_configuration_unsafe');
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (string) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432'),
            'database' => $database,
            'username' => (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: ''),
            'password' => (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ];
    }

    private static function bootstrapConnection(): \Illuminate\Database\Connection
    {
        if (self::$bootstrap === null) {
            self::$bootstrap = new Capsule;
            self::$bootstrap->addConnection(self::baseConfiguration(), 'isolated_postgres_bootstrap');
        }

        return self::$bootstrap->getConnection('isolated_postgres_bootstrap');
    }

    private static function registerCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }

        self::$cleanupRegistered = true;
        register_shutdown_function(static function (): void {
            if (self::$bootstrap === null) {
                return;
            }

            $connection = self::$bootstrap->getConnection('isolated_postgres_bootstrap');
            foreach (array_reverse(self::$schemas) as $schemaRecord) {
                $database = $schemaRecord['database'];
                $schema = $schemaRecord['schema'];
                if (preg_match('/^'.self::SCHEMA_PREFIX.'[a-f0-9]{24}$/D', $schema) !== 1) {
                    continue;
                }

                try {
                    self::schemaConnectionForDatabase($database)
                        ->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                } catch (Throwable) {
                }
            }

            foreach (array_reverse(self::$databases) as $database) {
                if (preg_match('/^'.self::DATABASE_PREFIX.'[a-f0-9]{24}'.self::DATABASE_SUFFIX.'$/D', $database) !== 1) {
                    continue;
                }

                try {
                    $connection->statement('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
                } catch (Throwable) {
                }
            }

            foreach (self::$schemaBootstraps as $capsule) {
                $capsule->getConnection()->disconnect();
            }

            $connection->disconnect();
        });
    }

    /** @param array<string, mixed> $configuration */
    private static function schemaConnection(array $configuration): Connection
    {
        $database = (string) $configuration['database'];
        if (! isset(self::$schemaBootstraps[$database])) {
            $capsule = new Capsule;
            $capsule->addConnection($configuration);
            self::$schemaBootstraps[$database] = $capsule;
        }

        return self::$schemaBootstraps[$database]->getConnection();
    }

    private static function schemaConnectionForDatabase(string $database): Connection
    {
        if (isset(self::$schemaBootstraps[$database])) {
            return self::$schemaBootstraps[$database]->getConnection();
        }

        $configuration = self::baseConfiguration();
        $configuration['database'] = $database;

        return self::schemaConnection($configuration);
    }
}
