<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurchaseContractPostgresRaceTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $connection;

    private string $schema;

    private string $databaseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('MOST_CONCURRENCY_TEST_DATABASE_URL');
        if (getenv('MOST_CONCURRENCY_TEST_ALLOW_DDL') !== '1' || ! is_string($url) || $url === '') {
            self::markTestSkipped('Dedicated PostgreSQL procurement concurrency database is not enabled.');
        }

        $this->databaseUrl = $url;
        $this->database = new Capsule;
        $this->database->addConnection($this->connectionConfig($url), 'procurement_race_parent');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('events', new Dispatcher($container));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $this->database->setEventDispatcher($container->make('events'));
        $this->database->bootEloquent();
        $this->database->getDatabaseManager()->setDefaultConnection('procurement_race_parent');
        $this->connection = $this->database->getConnection('procurement_race_parent');

        $database = (string) $this->connection->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        $this->schema = 'procurement_contract_race_'.bin2hex(random_bytes(6));
        $this->connection->statement("CREATE SCHEMA {$this->schema}");
        $this->connection->statement("SET search_path TO {$this->schema}");
        $this->installSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schema) && str_starts_with($this->schema, 'procurement_contract_race_')) {
            $this->connection->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_real_order_adapter_serializes_two_independent_postgresql_workers(): void
    {
        $this->connection->table('organizations')->insert(['id' => 7]);
        $this->connection->table('suppliers')->insert(['id' => 9]);
        $this->connection->table('legal_archive_documents')->insert(['id' => 41, 'organization_id' => 7]);
        $orderId = $this->connection->table('purchase_orders')->insertGetId([
            'organization_id' => 7,
            'supplier_id' => 9,
            'order_number' => 'PO-RACE-41',
            'status' => 'confirmed',
            'total_amount' => 100,
        ]);

        $leader = $this->startWorker('leader', $orderId);
        $follower = null;

        try {
            self::assertSame('locked', $this->readLine($leader['stdout']));

            $follower = $this->startWorker('follower', $orderId);
            self::assertSame('started', $this->readLine($follower['stdout']));
            $this->waitForDatabaseLock('most-procurement-contract-race-follower');

            fwrite($leader['stdin'], "release\n");
            $leaderResult = $this->readResult($leader['stdout']);
            $followerResult = $this->readResult($follower['stdout']);
            self::assertSame($leaderResult['contract_id'], $followerResult['contract_id']);
            self::assertSame(1, $this->connection->table('contracts')->count());
            self::assertSame(1, $this->connection->table('contract_dossier_sources')->count());
            self::assertSame($leaderResult['contract_id'], (int) $this->connection->table('purchase_orders')->where('id', $orderId)->value('contract_id'));
            $this->assertWorkerSucceeded($leader);
            $this->assertWorkerSucceeded($follower);
        } finally {
            $this->stopWorker($leader);
            if (is_array($follower)) {
                $this->stopWorker($follower);
            }
        }
    }

    private function startWorker(string $role, int $orderId): array
    {
        $worker = dirname(__DIR__, 4).'/Support/Procurement/PurchaseContractRaceWorker.php';
        if (! is_file($worker)) {
            throw new RuntimeException('Procurement contract race worker was not found.');
        }
        $environment = array_merge($_ENV, [
            'MOST_CONCURRENCY_TEST_DATABASE_URL' => $this->databaseUrl,
            'MOST_CONCURRENCY_TEST_SCHEMA' => $this->schema,
            'MOST_CONCURRENCY_TEST_ORDER_ID' => (string) $orderId,
        ]);
        $process = proc_open([PHP_BINARY, $worker, $role], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 5), $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start procurement contract race worker.');
        }

        stream_set_blocking($pipes[1], false);

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function readLine(mixed $stream): string
    {
        $read = [$stream];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 5, 0) !== 1 || ($line = fgets($stream)) === false) {
            throw new RuntimeException('Procurement contract race worker did not reach its barrier.');
        }

        return trim($line);
    }

    private function readResult(mixed $stream): array
    {
        return json_decode($this->readLine($stream), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForDatabaseLock(string $applicationName): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = $this->connection->table('pg_stat_activity')
                ->where('application_name', $applicationName)
                ->where('wait_event_type', 'Lock')
                ->exists();
            if ($waiting) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Follower did not block on the PostgreSQL purchase order lock.');
    }

    private function assertWorkerSucceeded(array $worker): void
    {
        fclose($worker['stdin']);
        self::assertSame(0, proc_close($worker['process']), stream_get_contents($worker['stderr']));
        fclose($worker['stdout']);
        fclose($worker['stderr']);
    }

    private function stopWorker(array $worker): void
    {
        if (is_resource($worker['stdin'])) {
            @fwrite($worker['stdin'], "release\n");
            @fclose($worker['stdin']);
        }
        if (is_resource($worker['process'])) {
            @proc_terminate($worker['process']);
        }
        foreach (['stdout', 'stderr'] as $pipe) {
            if (is_resource($worker[$pipe])) {
                @fclose($worker[$pipe]);
            }
        }
    }

    private function connectionConfig(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true) || empty($parts['path'])) {
            throw new RuntimeException('MOST_CONCURRENCY_TEST_DATABASE_URL must be a PostgreSQL URL.');
        }

        return ['driver' => 'pgsql', 'url' => $url, 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? 5432, 'database' => ltrim($parts['path'], '/'), 'username' => rawurldecode($parts['user'] ?? ''), 'password' => rawurldecode($parts['pass'] ?? ''), 'charset' => 'utf8', 'prefix' => ''];
    }

    private function installSchema(): void
    {
        foreach ([
            'CREATE TABLE organizations (id bigint PRIMARY KEY)',
            'CREATE TABLE suppliers (id bigint PRIMARY KEY)',
            'CREATE TABLE projects (id bigint PRIMARY KEY)',
            'CREATE TABLE contractors (id bigint PRIMARY KEY)',
            'CREATE TABLE legal_archive_documents (id bigint PRIMARY KEY, organization_id bigint NOT NULL, deleted_at timestamp NULL)',
            'CREATE TABLE contracts (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint NULL, contractor_id bigint NULL, supplier_id bigint NULL, number varchar(191) NOT NULL, status varchar(32) NOT NULL DEFAULT \'draft\', legal_archive_document_id bigint NULL UNIQUE, dossier_creation_key varchar(191) NULL, deleted_at timestamp NULL, created_at timestamp NULL, updated_at timestamp NULL, CONSTRAINT contracts_dossier_creation_key_unique UNIQUE (organization_id, dossier_creation_key))',
            'CREATE TABLE contract_dossier_sources (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, contract_id bigint NOT NULL, source_type varchar(64) NOT NULL, source_id varchar(191) NOT NULL, idempotency_key varchar(191) NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, CONSTRAINT contract_dossier_sources_source_unique UNIQUE (organization_id, source_type, source_id), CONSTRAINT contract_dossier_sources_key_unique UNIQUE (organization_id, idempotency_key))',
            'CREATE TABLE purchase_orders (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, supplier_id bigint NULL, purchase_request_id bigint NULL, external_supplier_contact_id bigint NULL, supplier_party_id bigint NULL, contract_id bigint NULL, order_number varchar(191) NOT NULL, status varchar(32) NOT NULL, total_amount numeric(15,2) NOT NULL, supplier_snapshot jsonb NULL, deleted_at timestamp NULL, created_at timestamp NULL, updated_at timestamp NULL)',
        ] as $statement) {
            $this->connection->statement($statement);
        }
    }
}
