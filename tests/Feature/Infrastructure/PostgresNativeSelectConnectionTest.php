<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Services\Monitoring\SqlConnectionState;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use LogicException;
use PDO;
use PDOStatement;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeParameterConnectionFactory;
use Tests\Support\NativeParameterSelectConnection;

final class PostgresNativeSelectConnectionTest extends TestCase
{
    private array $managers = [];

    public static function transports(): iterable
    {
        yield 'Laravel named prepare' => [false];
        yield 'Laravel statement parameters' => [true];
    }

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->purge('matrix');
        }
        $this->managers = [];
        parent::tearDown();
    }

    #[DataProvider('transports')]
    public function test_factory_driver_and_statement_transport_keep_connection_flags(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        self::assertInstanceOf($native ? NativeParameterSelectConnection::class : PostgresConnection::class, $connection);
        self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
        self::assertInstanceOf(\Closure::class, $connection->getRawReadPdo());
        $read = $connection->getReadPdo();
        $write = $connection->getPdo();
        self::assertInstanceOf(Pgsql::class, $read);
        self::assertInstanceOf(Pgsql::class, $write);
        self::assertNotSame($read, $write);
        $statements = [];
        $connection->getEventDispatcher()->listen(StatementPrepared::class, static function (StatementPrepared $event) use (&$statements): void {
            $statements[] = $event->statement;
        });
        self::assertSame(37, $connection->selectOne('SELECT CAST(? AS integer) AS factory_native_marker', [37])->factory_native_marker);
        $named = (int) $read->query(<<<'SQL'
SELECT count(*) FROM pg_prepared_statements
WHERE statement = 'SELECT CAST($1 AS integer) AS factory_native_marker'
SQL)->fetchColumn();
        self::assertSame($native ? 0 : 1, $named);
        self::assertCount(1, $statements);
        self::assertTrue($statements[0]->execute([41]));
        self::assertSame(41, $statements[0]->fetchColumn());
        self::assertSame(43, $connection->selectOne('SELECT CAST(? AS integer) AS interleaved', [43])->interleaved);
        $statements[0]->closeCursor();
        foreach ([$read, $write] as $pdo) {
            self::assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
            self::assertFalse($pdo->getAttribute(Pgsql::ATTR_DISABLE_PREPARES));
            self::assertFalse($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES));
        }
        $server = (int) $write->query('SHOW server_version_num')->fetchColumn();
        self::assertGreaterThanOrEqual(160000, $server);
        self::assertLessThan(170000, $server);
    }

    #[DataProvider('transports')]
    public function test_monitoring_keeps_runtime_flags_and_reports_custom_select_interval_limit(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        $observer = new SqlConnectionState;
        $observer->watch($connection);
        $observations = [];
        $connection->getEventDispatcher()->listen(StatementPrepared::class, static fn (StatementPrepared $event) => $observer->prepared($event->connection));
        $connection->getEventDispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($observer, &$observations): void {
            $observations[] = $observer->finish($event, true);
        });
        self::assertSame(3, $connection->selectOne('SELECT CAST(? AS integer) AS value', [3], false)->value);
        self::assertCount(1, $observations);
        self::assertTrue($observations[0]['observed']);
        self::assertSame(! $native, $observations[0]['event_intervals']['observed']);
        if ($native) {
            self::assertSame(['observed' => false], $observations[0]['event_intervals']);
        }
        self::assertSame([
            'observed' => true, 'php_version_id' => 80512,
            'write' => ['observed' => true, 'driver' => 'pgsql', 'emulate_prepares' => false, 'disable_prepares' => false],
            'read' => ['observed' => false],
        ], $observations[0]['pdo_runtime']);
        self::assertInstanceOf(\Closure::class, $connection->getRawReadPdo());
    }

    #[DataProvider('transports')]
    public function test_first_select_opens_only_the_chosen_lazy_handle(bool $native): void
    {
        foreach ([true, false] as $useReadPdo) {
            $connection = $this->manager($native)->connection();
            self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
            self::assertInstanceOf(\Closure::class, $connection->getRawReadPdo());
            self::assertSame(5, $connection->selectOne('SELECT CAST(? AS integer) AS value', [5], $useReadPdo)->value);
            self::assertInstanceOf($useReadPdo ? \Closure::class : Pgsql::class, $connection->getRawPdo());
            self::assertInstanceOf($useReadPdo ? Pgsql::class : \Closure::class, $connection->getRawReadPdo());
        }
    }

    #[DataProvider('transports')]
    public function test_read_write_transaction_and_sticky_routing_are_unchanged(bool $native): void
    {
        foreach ([false, true] as $sticky) {
            $connection = $this->manager($native, $sticky)->connection();
            $readPid = (int) $connection->getReadPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
            $writePid = (int) $connection->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
            self::assertNotSame($readPid, $writePid);
            self::assertSame($readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            self::assertSame($writePid, $connection->selectOne('SELECT pg_backend_pid() AS pid', [], false)->pid);
            self::assertSame($writePid, $connection->transaction(fn (): int => $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid));
            self::assertSame($readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $connection->useWriteConnectionWhenReading();
            self::assertSame($writePid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $connection->useWriteConnectionWhenReading(false);
            self::assertSame($readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $connection->statement('CREATE TEMPORARY TABLE native_select_sticky (id integer)');
            $connection->statement('INSERT INTO native_select_sticky (id) VALUES (?)', [1]);
            self::assertTrue($connection->hasModifiedRecords());
            self::assertSame($sticky ? $writePid : $readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
            self::assertSame(1, $connection->selectOne('SELECT count(*) AS count FROM native_select_sticky', [], false)->count);
            $connection->forgetRecordModificationState();
            self::assertSame($readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        }
        $fallback = $this->manager($native, false, false)->connection();
        self::assertNull($fallback->getRawReadPdo());
        self::assertSame($fallback->getPdo(), $fallback->getReadPdo());
        $fallback->setReadPdo(null);
        self::assertSame($fallback->getPdo(), $fallback->getReadPdo());
    }

    #[DataProvider('transports')]
    public function test_bindings_fetch_events_and_pretend_keep_laravel_contract(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        $events = [];
        $connection->beforeExecuting(static function () use (&$events): void { $events[] = 'before'; });
        $dispatcher = $connection->getEventDispatcher();
        $dispatcher->listen(StatementPrepared::class, static function (StatementPrepared $event) use (&$events): void {
            self::assertInstanceOf(PDOStatement::class, $event->statement);
            $events[] = 'prepared';
        });
        $dispatcher->listen(QueryExecuted::class, static function () use (&$events): void { $events[] = 'executed'; });
        $binary = fopen('php://memory', 'w+');
        self::assertIsResource($binary);
        fwrite($binary, "\x00\x01\xff");
        rewind($binary);
        try {
            $row = $connection->selectOne(<<<'SQL'
SELECT CAST(? AS boolean) AS enabled, CAST(? AS boolean) AS disabled,
CAST(? AS text) AS missing, to_char(CAST(? AS timestamp), 'YYYY-MM-DD HH24:MI:SS') AS happened,
CAST(? AS bigint) AS large_integer, CAST(? AS numeric(12,3)) AS decimal_value,
CAST(? AS jsonb)->>'key' AS json_value, encode(CAST(? AS bytea), 'hex') AS binary_value
SQL, [true, false, null, new DateTimeImmutable('2026-10-23 03:00:01+00:00'), PHP_INT_MAX, '123.450', '{"key":"проверка"}', $binary]);
            self::assertTrue($row->enabled);
            self::assertFalse($row->disabled);
            self::assertNull($row->missing);
            self::assertSame('2026-10-23 03:00:01', $row->happened);
            self::assertSame(PHP_INT_MAX, $row->large_integer);
            self::assertSame('123.450', $row->decimal_value);
            self::assertSame('проверка', $row->json_value);
            self::assertSame('0001ff', $row->binary_value);
            self::assertSame(['before', 'prepared', 'executed'], $events);
            $dispatcher->listen(StatementPrepared::class, static function (StatementPrepared $event): void { $event->statement->setFetchMode(PDO::FETCH_ASSOC); });
            self::assertSame([['value' => 7]], $connection->select('SELECT CAST(? AS integer) AS value', [7]));
            $events = [];
            $log = $connection->pretend(fn () => $connection->select('SELECT CAST(? AS integer) AS value', [9]));
            self::assertSame(['before', 'executed'], $events);
            self::assertCount(1, $log);
            self::assertSame([9], $log[0]['bindings']);
        } finally {
            fclose($binary);
        }
    }

    #[DataProvider('transports')]
    public function test_named_repeated_parameters_and_postgres_literals_use_laravel_binding(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        $row = $connection->selectOne(<<<'SQL'
SELECT CAST(:value AS integer) + CAST(:value AS integer) AS doubled,
CAST(:document AS jsonb) ?? 'key' AS has_key,
'literal ? :value' AS quoted, $$dollar ? :value$$ AS dollar_quoted,
CAST(:text AS text) AS bound_text
SQL, ['value' => 9, 'document' => '{"key":true}', 'text' => "Тест ' ? :value $1"]);
        self::assertSame(18, $row->doubled);
        self::assertTrue($row->has_key);
        self::assertSame('literal ? :value', $row->quoted);
        self::assertSame('dollar ? :value', $row->dollar_quoted);
        self::assertSame("Тест ' ? :value $1", $row->bound_text);
    }

    #[DataProvider('transports')]
    public function test_disconnect_reconnect_recreates_both_factory_handles(bool $native): void
    {
        $manager = $this->manager($native);
        $connection = $manager->connection();
        $oldRead = $connection->getReadPdo();
        $oldWrite = $connection->getPdo();
        $manager->disconnect('matrix');
        self::assertNull($connection->getRawPdo());
        self::assertNull($connection->getRawReadPdo());
        self::assertSame(11, $connection->selectOne('SELECT CAST(? AS integer) AS value', [11])->value);
        self::assertNotSame($oldRead, $connection->getReadPdo());
        self::assertNotSame($oldWrite, $connection->getPdo());
        self::assertInstanceOf(Pgsql::class, $connection->getReadPdo());
        self::assertInstanceOf(Pgsql::class, $connection->getPdo());
        self::assertFalse($connection->getReadPdo()->getAttribute(Pgsql::ATTR_DISABLE_PREPARES));
        self::assertFalse($connection->getPdo()->getAttribute(Pgsql::ATTR_DISABLE_PREPARES));
    }

    #[DataProvider('transports')]
    public function test_sqlstate_and_timeout_do_not_reconnect(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void { $reconnects++; });
        try {
            $connection->selectOne('SELECT CAST(? AS integer) AS value', ['not-an-integer']);
            self::fail('Invalid integer must fail');
        } catch (QueryException $failure) {
            self::assertSame('22P02', $failure->getCode());
            self::assertSame(['not-an-integer'], $failure->getBindings());
        }
        self::assertSame(0, $reconnects);
        self::assertSame(13, $connection->selectOne('SELECT CAST(? AS integer) AS value', [13])->value);
        $read = $connection->getReadPdo();
        $read->exec("SET statement_timeout = '50ms'");
        try {
            $connection->selectOne('SELECT pg_sleep(0.2)');
            self::fail('Statement timeout must fail');
        } catch (QueryException $failure) {
            self::assertSame('57014', $failure->getCode());
        } finally {
            $read->exec('SET statement_timeout = 0');
        }
        self::assertSame(0, $reconnects);
        self::assertSame(17, $connection->selectOne('SELECT CAST(? AS integer) AS value', [17])->value);
    }

    #[DataProvider('transports')]
    public function test_lost_read_connection_retries_once_with_laravel_event_order(bool $native): void
    {
        $manager = $this->manager($native);
        $connection = $manager->connection();
        $pid = (int) $connection->getReadPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
        $control = $this->manager(false, false, false)->connection();
        self::assertTrue($control->selectOne('SELECT pg_terminate_backend(?) AS stopped', [$pid])->stopped);
        $before = 0;
        $prepared = 0;
        $executed = 0;
        $reconnects = 0;
        $connection->beforeExecuting(static function () use (&$before): void { $before++; });
        $connection->getEventDispatcher()->listen(StatementPrepared::class, static function () use (&$prepared): void { $prepared++; });
        $connection->getEventDispatcher()->listen(QueryExecuted::class, static function () use (&$executed): void { $executed++; });
        $connection->setReconnector(static function (Connection $actual) use ($manager, &$reconnects): void {
            $reconnects++;
            $manager->reconnect($actual->getName());
        });
        self::assertSame(23, $connection->selectOne('SELECT CAST(? AS integer) AS value', [23])->value);
        self::assertSame(1, $reconnects);
        self::assertSame(1, $before);
        self::assertSame(2, $prepared);
        self::assertSame(1, $executed);
        self::assertNotSame($pid, (int) $connection->getReadPdo()->query('SELECT pg_backend_pid()')->fetchColumn());
    }

    #[DataProvider('transports')]
    public function test_lost_write_handle_outside_transaction_retries_the_write_route(bool $native): void
    {
        $manager = $this->manager($native);
        $connection = $manager->connection();
        $pid = (int) $connection->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
        $control = $this->manager(false, false, false)->connection();
        self::assertTrue($control->selectOne('SELECT pg_terminate_backend(?) AS stopped', [$pid])->stopped);
        $reconnects = 0;
        $connection->setReconnector(static function (Connection $actual) use ($manager, &$reconnects): void {
            $reconnects++;
            $manager->reconnect($actual->getName());
        });
        self::assertSame(19, $connection->selectOne('SELECT CAST(? AS integer) AS value', [19], false)->value);
        self::assertSame(1, $reconnects);
        self::assertNotSame($pid, (int) $connection->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn());
        self::assertInstanceOf(\Closure::class, $connection->getRawReadPdo());
        self::assertFalse($connection->getPdo()->getAttribute(Pgsql::ATTR_DISABLE_PREPARES));
    }

    #[DataProvider('transports')]
    public function test_lost_connection_in_transaction_never_replays(bool $native): void
    {
        $manager = $this->manager($native);
        $connection = $manager->connection();
        $connection->beginTransaction();
        $pid = (int) $connection->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
        $control = $this->manager(false, false, false)->connection();
        self::assertTrue($control->selectOne('SELECT pg_terminate_backend(?) AS stopped', [$pid])->stopped);
        $reconnects = 0;
        $connection->setReconnector(static function () use (&$reconnects): void { $reconnects++; });
        try {
            $connection->selectOne('SELECT CAST(? AS integer) AS value', [29]);
            self::fail('Lost transaction connection must fail');
        } catch (QueryException) {
            self::assertSame(0, $reconnects);
            self::assertSame(1, $connection->transactionLevel());
        } finally {
            $manager->purge('matrix');
        }
    }

    #[DataProvider('transports')]
    public function test_prepared_observer_exceptions_preserve_query_exception_boundary(bool $native): void
    {
        $connection = $this->manager($native)->connection();
        $connection->getEventDispatcher()->listen(StatementPrepared::class, static function (): void { throw new LogicException('native-select-observer'); });
        try {
            $connection->selectOne('SELECT CAST(? AS integer) AS value', [31]);
            self::fail('Observer exception must fail');
        } catch (QueryException $failure) {
            self::assertInstanceOf(LogicException::class, $failure->getPrevious());
            self::assertSame('native-select-observer', $failure->getPrevious()->getMessage());
        }
    }

    private function manager(bool $native, bool $sticky = false, bool $withRead = true): DatabaseManager
    {
        self::assertSame(80512, PHP_VERSION_ID);
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) getenv('DB_DATABASE'));
        self::assertNull(Connection::getResolver('pgsql'));
        $config = [
            'driver' => 'pgsql', 'host' => (string) getenv('DB_HOST'), 'port' => (string) getenv('DB_PORT'),
            'database' => (string) getenv('DB_DATABASE'), 'username' => (string) getenv('DB_USERNAME'), 'password' => (string) getenv('DB_PASSWORD'),
            'prefix' => '', 'charset' => 'utf8', 'search_path' => 'public', 'sticky' => $sticky,
            'options' => [PDO::ATTR_EMULATE_PREPARES => false, Pgsql::ATTR_DISABLE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false],
        ];
        if ($withRead) {
            $config['read'] = ['host' => (string) getenv('DB_HOST')];
            $config['write'] = ['host' => (string) getenv('DB_HOST')];
        }
        $container = new Container;
        $container->instance('config', new Repository(['database' => ['default' => 'matrix', 'connections' => ['matrix' => $config]]]));
        $container->instance('events', new Dispatcher);
        $factory = $native ? new NativeParameterConnectionFactory($container) : new ConnectionFactory($container);
        $manager = new DatabaseManager($container, $factory);
        $this->managers[] = $manager;

        return $manager;
    }
}
