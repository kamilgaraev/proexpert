<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class PhpUnitPostgresProfileTest extends TestCase
{
    public function test_profile_guard_accepts_only_matching_loopback_ports(): void
    {
        self::assertSame(55433, IsolatedPostgresTestDatabase::profilePort(''));
        self::assertSame(55443, IsolatedPostgresTestDatabase::profilePort('ai-assistant'));
        foreach (['' => 55433, 'ai-assistant' => 55443] as $profile => $port) {
            IsolatedPostgresTestDatabase::assertSafeConfiguration([
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port,
                'database' => 'most_phpunit_'.str_repeat('a', 24).'_testing',
            ], $profile);
        }
    }

    public function test_profile_guard_rejects_foreign_endpoints_and_missing_opt_in(): void
    {
        $safe = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55433, 'database' => 'most_backend_testing'];
        $cases = [[$safe, 'foreign-project'], [$safe, 'ai-assistant'],
            [array_replace($safe, ['port' => 55443]), ''],
            [array_replace($safe, ['port' => 5432]), ''],
            [array_replace($safe, ['port' => 55444]), 'ai-assistant'],
            [array_replace($safe, ['host' => 'localhost']), ''],
            [array_replace($safe, ['host' => '192.0.2.1']), ''],
            [array_replace($safe, ['driver' => 'mysql']), ''],
            [array_replace($safe, ['database' => 'most_production']), ''],
            [array_replace($safe, ['database' => 'bad/testing']), ''],
            [array_replace($safe, ['url' => 'postgres://unsafe']), '']];
        foreach ($cases as [$configuration, $profile]) {
            try {
                IsolatedPostgresTestDatabase::assertSafeConfiguration($configuration, $profile);
                self::fail('Unsafe PostgreSQL profile was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertContains($exception->getMessage(), ['postgres_test_profile_unsafe', 'postgres_test_database_configuration_unsafe']);
            }
        }
    }

    public function test_default_phpunit_profile_uses_isolated_postgresql(): void
    {
        self::assertSame('pgsql', getenv('DB_CONNECTION'));
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertSame((string) IsolatedPostgresTestDatabase::profilePort(), getenv('DB_PORT'));
        self::assertMatchesRegularExpression(
            '/^most_phpunit_[a-f0-9]{24}_testing$/D',
            (string) getenv('DB_DATABASE'),
        );
        self::assertSame('most_backend_testing', getenv('PHPUNIT_ROOT_DB_DATABASE'));

        $connection = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST'),
                getenv('DB_PORT'),
                getenv('DB_DATABASE'),
            ),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $database = $connection->query('SELECT current_database()')->fetchColumn();
        $version = $connection->query('SHOW server_version')->fetchColumn();

        self::assertSame(getenv('DB_DATABASE'), $database);
        self::assertIsString($version);
        self::assertStringStartsWith('16.', $version);
        self::assertSame('public', getenv('DB_SCHEMA'));
    }

    public function test_material_supply_suite_is_declared_and_runner_selects_only_known_suites(): void
    {
        $configuration = simplexml_load_file(dirname(__DIR__, 3).'/phpunit.xml');

        self::assertNotFalse($configuration);

        $materialSuite = $configuration->xpath('/phpunit/testsuites/testsuite[@name="MaterialSupply"]');

        self::assertCount(1, $materialSuite);

        $files = array_map(
            static fn (\SimpleXMLElement $file): string => (string) $file,
            iterator_to_array($materialSuite[0]->file, false),
        );

        self::assertContains(
            'tests/Feature/Api/V1/Admin/ProcurementSupplierFlowCoreExperienceControllerTest.php',
            $files,
        );
        self::assertContains('tests/Feature/Procurement/ProcurementLifecycleServiceTest.php', $files);
        self::assertContains('tests/Feature/Api/V1/Admin/ProcurementChainControllerTest.php', $files);
        self::assertContains('tests/Feature/Api/V1/Admin/WarehouseOperationsControllerTest.php', $files);
        self::assertContains('tests/Feature/Api/V1/Admin/WarehouseAssetCatalogTest.php', $files);
        self::assertContains('tests/Feature/BasicWarehouse/WarehouseCustodyFlowTest.php', $files);
        self::assertContains('tests/Feature/BasicWarehouse/WarehouseJournalConsumptionTest.php', $files);
        self::assertContains('tests/Feature/Api/V1/Mobile/ProjectMaterialDeliveryMobileSyncTest.php', $files);
        self::assertContains(
            'tests/Unit/Procurement/Reporting/Award/ProcurementAwardOwnerEventRecorderTest.php',
            $files,
        );
        self::assertContains(
            'tests/Unit/Procurement/Reporting/Cycle/ProcurementCycleOwnerWorkflowContractTest.php',
            $files,
        );
        self::assertNotContains(
            'tests/Feature/Procurement/Reporting/Award/ProcurementAwardSourcePostgresTest.php',
            $files,
        );

        $runner = file_get_contents(dirname(__DIR__, 2).'/Runtime/run-postgres-tests.ps1');

        self::assertIsString($runner);
        self::assertStringContainsString('[string] $TestSuite', $runner);
        self::assertStringContainsString('postgres_test_suite_invalid', $runner);
        self::assertStringContainsString("@('--testsuite', \$TestSuite)", $runner);

        $baseTestCase = file_get_contents(dirname(__DIR__, 2).'/TestCase.php');

        self::assertIsString($baseTestCase);
        self::assertStringContainsString('transactionLevel() > 0', $baseTestCase);
    }

    public function test_phpunit_tree_has_no_runtime_sqlite_connections(): void
    {
        $testsRoot = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsRoot));
        $violations = [];
        $runtimeSqlitePatterns = [
            "'driver' => 'sqlite'",
            '"driver" => "sqlite"',
            "new PDO('sqlite:",
            'new PDO("sqlite:',
            'new SQLiteConnection',
            'new SQLiteConnector',
            'extends SQLiteConnection',
        ];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            foreach ($runtimeSqlitePatterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($testsRoot)) + 1));
                    break;
                }
            }
        }

        sort($violations, SORT_STRING);

        self::assertSame([], $violations, 'Runtime SQLite remains in: '.implode(', ', $violations));
    }
}
