<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\SqlConnectionState;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

final class SqlPreparedIntervalsTest extends TestCase
{
    public function test_real_postgres_lazy_write_read_ready_and_cursor_boundaries(): void
    {
        self::assertTrue($this->app->environment('testing'));
        $base = config('database.connections.'.DB::getDefaultConnection());
        self::assertIsArray($base);
        self::assertSame('pgsql', $base['driver']);
        self::assertStringStartsWith('most_phpunit_', $base['database']);
        $config = $base;
        $config['write'] ??= $base;
        $config['read'] ??= $base;
        $connection = (new ConnectionFactory($this->app))->make($config, 'prepared_intervals_test');
        $connection->setEventDispatcher(new Dispatcher($this->app));
        $observer = new SqlConnectionState;
        $observations = [];
        $prepared = 0;
        $cursorRows = 0;
        $rowsAtQueryEvent = [];
        $connection->getEventDispatcher()->listen(StatementPrepared::class, static function (StatementPrepared $event) use ($observer, &$prepared): void {
            $prepared++;
            $observer->prepared($event->connection);
        });
        $connection->getEventDispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($observer, &$observations, &$cursorRows, &$rowsAtQueryEvent): void {
            $observations[] = $observer->finish($event);
            $rowsAtQueryEvent[] = $cursorRows;
        });
        try {
            $observer->watch($connection);
            self::assertNotInstanceOf(PDO::class, $connection->getRawPdo());
            self::assertNotInstanceOf(PDO::class, $connection->getRawReadPdo());
            $version = (int) $connection->selectOne("select current_setting('server_version_num')::int as value", [], false)->value;
            self::assertSame(16, intdiv($version, 10000));
            self::assertTrue($observations[0]['write_lazy_before']);
            self::assertTrue($observations[0]['read_lazy_before']);
            self::assertTrue($observations[0]['write_ready_after']);
            self::assertFalse($observations[0]['read_ready_after']);
            self::assertSame(1, (int) $connection->selectOne('select 1 as value')->value);
            self::assertTrue($observations[1]['read_lazy_before']);
            self::assertTrue($observations[1]['read_ready_after']);
            $connection->selectOne('select pg_sleep(0.02) as value', [], false);
            self::assertTrue($observations[2]['write_ready_before']);
            self::assertGreaterThanOrEqual(15, $observations[2]['event_intervals']['prepared_to_query_event_ms']);
            foreach ($connection->cursor('select generate_series(1, 3) as value') as $row) {
                self::assertSame($cursorRows + 1, (int) $row->value);
                self::assertCount(4, $observations);
                self::assertSame(0, $rowsAtQueryEvent[3]);
                self::assertTrue($observations[3]['event_intervals']['observed']);
                $cursorRows++;
            }
            self::assertSame(3, $cursorRows);
            self::assertSame(4, $prepared);
            foreach ($observations as $observation) {
                self::assertTrue($observation['observed']);
                self::assertTrue($observation['event_intervals']['observed']);
                self::assertGreaterThanOrEqual(0, $observation['event_intervals']['before_to_prepared_ms']);
                self::assertGreaterThanOrEqual(0, $observation['event_intervals']['prepared_to_query_event_ms']);
            }
            self::assertTrue($connection->statement('select 1'));
            self::assertCount(5, $observations);
            self::assertSame(4, $prepared);
            self::assertTrue($observations[4]['observed']);
            self::assertSame(['observed' => false], $observations[4]['event_intervals']);
        } finally {
            $connection->disconnect();
        }
    }
}
