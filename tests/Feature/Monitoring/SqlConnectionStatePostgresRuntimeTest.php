<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\SlowQueryContext;
use App\Services\Monitoring\SqlConnectionState;
use App\Services\Monitoring\TracingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\PostgresConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Pgsql;
use Tests\TestCase;

final class SqlConnectionStatePostgresRuntimeTest extends TestCase
{
    public function test_real_postgres_modes_are_observed_without_changing_the_connection(): void
    {
        $pdo = DB::connection()->getPdo();
        self::assertSame('pgsql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $originalEmulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $originalDisable = $pdo->getAttribute(Pgsql::ATTR_DISABLE_PREPARES);
        $connection = new PostgresConnection($pdo);
        $events = new Dispatcher;
        $connection->setEventDispatcher($events);
        $observer = new SqlConnectionState;
        $observer->watch($connection);
        $observations = [];
        $events->listen(StatementPrepared::class, static fn (StatementPrepared $event) => $observer->prepared($event->connection));
        $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($observer, &$observations): void {
            $observations[] = [$event, $observer->finish($event, true)];
        });
        try {
            foreach ([[false, false], [false, true], [true, false], [true, true]] as [$emulate, $disable]) {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
                $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, $disable);
                self::assertSame(1, $connection->selectOne('select 1 as value', [], false)->value);
                [$event, $state] = $observations[array_key_last($observations)];
                self::assertTrue($state['observed']);
                self::assertTrue($state['event_intervals']['observed']);
                $expected = ['observed' => true, 'php_version_id' => PHP_VERSION_ID,
                    'write' => ['observed' => true, 'driver' => 'pgsql', 'emulate_prepares' => $emulate, 'disable_prepares' => $disable],
                    'read' => ['observed' => false]];
                self::assertSame($expected, $state['pdo_runtime']);
                self::assertSame($expected, (new SlowQueryContext(new TracingService(null)))->forQuery($event, $state)['pdo_runtime']);
                self::assertSame($emulate, $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
                self::assertSame($disable, $pdo->getAttribute(Pgsql::ATTR_DISABLE_PREPARES));
            }
            self::assertCount(4, $observations);
            $connection->setReadPdo($pdo);
            $connection->selectOne('select 1 as value');
            $state = $observations[array_key_last($observations)][1];
            self::assertSame($state['pdo_runtime']['write'], $state['pdo_runtime']['read']);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $originalEmulate);
            $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, $originalDisable);
        }
    }
}
