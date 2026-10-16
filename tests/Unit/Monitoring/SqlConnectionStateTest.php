<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\SqlConnectionState;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class SqlConnectionStateTest extends TestCase
{
    private array $observations = [];

    public function test_lazy_write_handle_is_not_materialized_by_observation(): void
    {
        $observer = new SqlConnectionState;
        $created = 0;
        $connection = $this->connection($observer, static function () use (&$created): PDO {
            $created++;

            return new ConnectionStateTestPdo;
        });
        $observer->watch($connection);
        self::assertSame(0, $created);
        $connection->select('select 1', [], false);
        self::assertSame(1, $created);
        self::assertSame([
            'observed' => true,
            'write_lazy_before' => true,
            'read_lazy_before' => false,
            'write_ready_before' => false,
            'read_ready_before' => false,
            'write_ready_after' => true,
            'read_ready_after' => false,
            'manager_connection_event_during_run' => false,
        ], $this->observations[0]);
        $connection->select('select 1', [], false);
        self::assertTrue($this->observations[1]['write_ready_before']);
        self::assertFalse($this->observations[1]['write_lazy_before']);
    }

    public function test_lazy_read_handle_and_null_read_fallback_are_distinguished(): void
    {
        $observer = new SqlConnectionState;
        $created = 0;
        $connection = $this->connection($observer, new ConnectionStateTestPdo, static function () use (&$created): PDO {
            $created++;

            return new ConnectionStateTestPdo;
        });
        self::assertSame(0, $created);
        $connection->select('select 1');
        self::assertSame(1, $created);
        self::assertTrue($this->observations[0]['read_lazy_before']);
        self::assertTrue($this->observations[0]['read_ready_after']);

        $fallback = $this->connection($observer, new ConnectionStateTestPdo);
        $fallback->select('select 1');
        self::assertTrue($this->observations[1]['observed']);
        self::assertFalse($this->observations[1]['read_lazy_before']);
        self::assertFalse($this->observations[1]['read_ready_after']);
        self::assertTrue($this->observations[1]['write_ready_after']);
    }

    public function test_successful_lost_connection_retry_records_manager_event_without_repeating_hook(): void
    {
        $observer = new SqlConnectionState;
        $attempts = 0;
        $failed = new ConnectionStateTestPdo(static function () use (&$attempts): void {
            $attempts++;
            throw new PDOException('server has gone away');
        });
        $recovered = new ConnectionStateTestPdo(static function () use (&$attempts): void {
            $attempts++;
        });
        $connection = $this->connection($observer, $failed);
        $connection->setReconnector(static function (Connection $current) use ($recovered): void {
            $current->setPdo($recovered);
            $current->getEventDispatcher()->dispatch(new ConnectionEstablished($current));
        });
        $connection->select('select 1', [], false);
        self::assertSame(2, $attempts);
        self::assertCount(1, $this->observations);
        self::assertTrue($this->observations[0]['observed']);
        self::assertTrue($this->observations[0]['manager_connection_event_during_run']);
        self::assertTrue($this->observations[0]['write_ready_before']);
        self::assertTrue($this->observations[0]['write_ready_after']);
    }

    public function test_nested_success_then_failed_identical_query_cannot_be_attributed_to_outer_run(): void
    {
        $observer = new SqlConnectionState;
        $connection = null;
        $nested = false;
        $pdo = new ConnectionStateTestPdo(static function () use (&$connection, &$nested): void {
            if (! $nested) {
                $nested = true;
                $connection->select('select nested', [], false);
            }
        });
        $connection = $this->connection($observer, $pdo);
        $connection->getEventDispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($pdo): void {
            if ($event->sql !== 'select nested') {
                return;
            }
            $event->connection->setPdo(new ConnectionStateTestPdo(static function (): void {
                throw new PDOException('synthetic statement failure');
            }));
            try {
                $event->connection->select('select outer', [], false);
            } catch (QueryException) {
            } finally {
                $event->connection->setPdo($pdo);
            }
        });
        $connection->select('select outer', [], false);
        $connection->select('select after', [], false);
        $connection->getEventDispatcher()->dispatch(new ConnectionEstablished($connection));
        $connection->select('select after reconnect', [], false);
        self::assertSame(array_fill(0, 4, ['observed' => false]), $this->observations);

        $other = $this->connection($observer, new ConnectionStateTestPdo);
        $other->select('select 1', [], false);
        self::assertTrue($this->observations[4]['observed']);
    }

    public function test_failed_statement_permanently_invalidates_ambiguous_following_observations(): void
    {
        $observer = new SqlConnectionState;
        $connection = $this->connection($observer, new ConnectionStateTestPdo(static function (): void {
            throw new PDOException('synthetic statement failure');
        }));
        try {
            $connection->select('select same', [], false);
            self::fail('The original query must still fail');
        } catch (QueryException) {
        }
        $connection->setPdo(new ConnectionStateTestPdo);
        $connection->select('select same', [], false);
        $connection->select('select another', [], false);
        self::assertSame([['observed' => false], ['observed' => false]], $this->observations);
    }

    public function test_connection_is_not_retained_by_weak_state_or_callback(): void
    {
        $observer = new SqlConnectionState;
        $connection = $this->connection($observer, new ConnectionStateTestPdo);
        $connection->select('select 1', [], false);
        $reference = WeakReference::create($connection);
        unset($connection);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }

    public function test_custom_raw_getter_is_never_called_by_instrumentation(): void
    {
        $observer = new SqlConnectionState;
        $connection = new class(new ConnectionStateTestPdo) extends Connection
        {
            public int $calls = 0;

            public function getRawPdo(): PDO
            {
                $this->calls++;
                throw new PDOException('must not be called');
            }
        };
        $observer->watch($connection);
        self::assertSame(['observed' => false], $observer->finish(new QueryExecuted('select 1', [], 600, $connection)));
        self::assertSame(0, $connection->calls);
        self::assertSame([['value' => 1]], $connection->select('select 1', [], false));
    }

    private function connection(SqlConnectionState $observer, PDO|Closure|null $write, PDO|Closure|null $read = null): Connection
    {
        $connection = new Connection($write, 'test', '', ['name' => 'test']);
        $connection->setReadPdo($read);
        $events = new Dispatcher;
        $connection->setEventDispatcher($events);
        $events->listen(ConnectionEstablished::class, static fn (ConnectionEstablished $event) => $observer->established($event->connection));
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use ($observer): void {
            $this->observations[] = $observer->finish($event);
        });
        $events->dispatch(new ConnectionEstablished($connection));

        return $connection;
    }
}

final class ConnectionStateTestPdo extends PDO
{
    public function __construct(private readonly ?Closure $duringExecute = null) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ConnectionStateTestStatement($this->duringExecute);
    }
}

final class ConnectionStateTestStatement extends PDOStatement
{
    public function __construct(private readonly ?Closure $duringExecute) {}

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        ($this->duringExecute)?->__invoke();

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [['value' => 1]];
    }
}
