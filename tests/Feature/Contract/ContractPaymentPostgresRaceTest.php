<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContractPaymentPostgresRaceTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $connection;

    private string $schema;

    private array $databaseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) getenv('DB_HOST');
        $port = (string) getenv('DB_PORT');
        $database = (string) getenv('DB_DATABASE');
        $username = (string) getenv('DB_USERNAME');
        $password = (string) getenv('DB_PASSWORD');
        if ($host !== '127.0.0.1' || $port !== '55433' || preg_match('/_testing$/D', $database) !== 1) {
            self::markTestSkipped('Contract payment concurrency test requires the isolated PostgreSQL 16 test database.');
        }

        $this->databaseConfig = compact('host', 'port', 'database', 'username', 'password') + [
            'driver' => 'pgsql', 'charset' => 'utf8', 'prefix' => '',
        ];
        $this->database = new Capsule;
        $this->database->addConnection($this->databaseConfig, 'contract_payment_race_parent');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('events', new Dispatcher($container));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $this->database->setEventDispatcher($container->make('events'));
        $this->database->bootEloquent();
        $this->database->getDatabaseManager()->setDefaultConnection('contract_payment_race_parent');
        $this->connection = $this->database->getConnection('contract_payment_race_parent');

        $this->schema = 'contract_payment_race_'.bin2hex(random_bytes(6));
        $this->connection->statement("CREATE SCHEMA {$this->schema}");
        $this->connection->statement("SET search_path TO {$this->schema}");
        $this->installSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schema) && str_starts_with($this->schema, 'contract_payment_race_')) {
            $this->connection->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_payment_writer_lock_serializes_against_contract_financial_update(): void
    {
        $this->connection->table('contracts')->insert([
            'id' => 100, 'organization_id' => 7, 'total_amount' => '1000.00', 'currency' => 'RUB',
        ]);
        $documentId = $this->connection->table('payment_documents')->insertGetId([
            'organization_id' => 7,
            'document_type' => 'invoice',
            'document_number' => 'CONTRACT-RACE-100',
            'document_date' => now()->toDateString(),
            'invoiceable_type' => \App\Models\Contract::class,
            'invoiceable_id' => 100,
            'amount' => '600.00',
            'currency' => 'RUB',
            'paid_amount' => '0.00',
            'remaining_amount' => '600.00',
            'status' => 'approved',
        ]);

        $payment = $this->startWorker('payment', $documentId);
        $update = null;
        try {
            self::assertSame('contract_locked', $this->readLine($payment['stdout']));
            $update = $this->startWorker('update', $documentId);
            self::assertSame('update_started', $this->readLine($update['stdout']));
            $this->waitForDatabaseLock('most-contract-payment-race-update');

            fwrite($payment['stdin'], "release\n");
            self::assertSame('payment_committed', $this->readLine($payment['stdout']));
            self::assertSame('financial_conflict', $this->readLine($update['stdout']));
            self::assertSame('600.00', (string) $this->connection->table('payment_transactions')->sum('amount'));
            self::assertSame('1000.00', (string) $this->connection->table('contracts')->where('id', 100)->value('total_amount'));
            $this->assertWorkerSucceeded($payment);
            $this->assertWorkerSucceeded($update);
        } finally {
            $this->stopWorker($payment);
            if (is_array($update)) {
                $this->stopWorker($update);
            }
        }
    }

    private function startWorker(string $role, int $documentId): array
    {
        $worker = dirname(__DIR__, 2).'/Support/Contract/ContractPaymentRaceWorker.php';
        if (! is_file($worker)) {
            throw new RuntimeException('Contract payment race worker was not found.');
        }
        $environment = array_merge($_ENV, [
            'MOST_CONTRACT_PAYMENT_RACE_HOST' => $this->databaseConfig['host'],
            'MOST_CONTRACT_PAYMENT_RACE_PORT' => $this->databaseConfig['port'],
            'MOST_CONTRACT_PAYMENT_RACE_DATABASE' => $this->databaseConfig['database'],
            'MOST_CONTRACT_PAYMENT_RACE_USERNAME' => $this->databaseConfig['username'],
            'MOST_CONTRACT_PAYMENT_RACE_PASSWORD' => $this->databaseConfig['password'],
            'MOST_CONTRACT_PAYMENT_RACE_SCHEMA' => $this->schema,
            'MOST_CONTRACT_PAYMENT_RACE_DOCUMENT_ID' => (string) $documentId,
        ]);
        $process = proc_open([PHP_BINARY, $worker, $role], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start contract payment race worker.');
        }
        stream_set_blocking($pipes[1], false);

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function readLine(mixed $stream): string
    {
        $read = [$stream];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 10, 0) !== 1 || ($line = fgets($stream)) === false) {
            throw new RuntimeException('Contract payment race worker did not reach its barrier.');
        }

        return trim($line);
    }

    private function waitForDatabaseLock(string $applicationName): void
    {
        $deadline = microtime(true) + 10;
        do {
            if ($this->connection->table('pg_stat_activity')
                ->where('application_name', $applicationName)
                ->where('wait_event_type', 'Lock')
                ->exists()) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Contract update worker did not wait for the payment writer contract lock.');
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

    private function installSchema(): void
    {
        foreach ([
            'CREATE TABLE contracts (id bigint PRIMARY KEY, organization_id bigint NOT NULL, total_amount numeric(15,2) NOT NULL, actual_advance_amount numeric(15,2) NULL, planned_advance_amount numeric(15,2) NULL, currency varchar(3) NOT NULL DEFAULT \'RUB\', deleted_at timestamp NULL)',
            'CREATE TABLE contract_performance_acts (id bigserial PRIMARY KEY, contract_id bigint NOT NULL, amount numeric(15,2) NOT NULL DEFAULT 0, status varchar(32) NOT NULL DEFAULT \'draft\', is_approved boolean NOT NULL DEFAULT false)',
            'CREATE TABLE completed_works (id bigserial PRIMARY KEY, contract_id bigint NOT NULL)',
            'CREATE TABLE contract_builder_instances (id bigserial PRIMARY KEY, contract_id bigint NOT NULL)',
            'CREATE TABLE payment_documents (id bigserial PRIMARY KEY, organization_id bigint NOT NULL, document_type varchar(32) NOT NULL, document_number varchar(100) NOT NULL, document_date date NOT NULL, invoiceable_type varchar(255) NULL, invoiceable_id bigint NULL, source_type varchar(255) NULL, source_id bigint NULL, invoice_type varchar(32) NULL, amount numeric(15,2) NOT NULL, currency varchar(3) NOT NULL, paid_amount numeric(15,2) NOT NULL DEFAULT 0, remaining_amount numeric(15,2) NOT NULL, status varchar(32) NOT NULL, metadata jsonb NULL, deleted_at timestamp NULL)',
            'CREATE TABLE payment_transactions (id bigserial PRIMARY KEY, payment_document_id bigint NOT NULL, organization_id bigint NOT NULL, amount numeric(15,2) NOT NULL, status varchar(32) NOT NULL)',
        ] as $statement) {
            $this->connection->statement($statement);
        }
    }
}
