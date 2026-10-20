<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotFootprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class AssistantStatusSnapshotPlanProfileTest extends TestCase
{
    public function test_existing_explain_reports_planning_without_executing_the_plan(): void
    {
        $connection = DB::connection();
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', $connection->getDatabaseName());
        $original = $connection->getEventDispatcher();
        self::assertNotNull($original);
        $dispatcher = clone $original;
        $queries = [];
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = [$event->sql, $event->bindings];
        });
        $connection->setEventDispatcher($dispatcher);
        $calls = 0;
        $sql = 'WITH first_rows AS MATERIALIZED (SELECT id FROM public.projects) SELECT * FROM first_rows WHERE EXISTS (SELECT 1 FROM public.organizations)';
        try {
            $result = app(AssistantStatusSnapshotFootprint::class)->capture(static function () use (&$calls, $connection, $sql): array {
                $calls++;

                return $connection->select($sql);
            });
            self::assertSame(1, $calls);
            self::assertSame(['public.organizations', 'public.projects'], $result['relations']);
            self::assertSame($dispatcher, $connection->getEventDispatcher());
            self::assertCount(1, array_filter($queries, static fn (array $query): bool => $query[0] === $sql));
            $explains = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with($query[0], 'EXPLAIN ')));
            self::assertCount(1, $explains);
            $row = $connection->selectOne($explains[0][0], $explains[0][1]);
            $json = (string) ((array) $row)['QUERY PLAN'];
            $plan = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('Planning Time', $plan[0]);
            self::assertIsNumeric($plan[0]['Planning Time']);
            foreach (['Execution Time', 'Actual Rows', 'Actual Total Time'] as $field) {
                self::assertStringNotContainsString($field, $json);
            }
        } finally {
            $connection->setEventDispatcher($original);
        }
        self::assertSame($original, $connection->getEventDispatcher());
    }

    public function test_slow_plan_diagnostic_failure_preserves_relations_and_listeners(): void
    {
        $connection = DB::connection();
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', $connection->getDatabaseName());
        $original = $connection->getEventDispatcher();
        self::assertNotNull($original);
        $dispatcher = clone $original;
        $explains = 0;
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$explains): void {
            if (str_starts_with($event->sql, 'EXPLAIN ')) {
                $explains++;
                usleep(520000);
            }
        });
        $connection->setEventDispatcher($dispatcher);
        $originalLog = Log::getFacadeRoot();
        $logged = null;
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('assistant.status_snapshot.plan_profile', Mockery::on(static function (array $context) use (&$logged): bool {
            $logged = $context;

            return true;
        }))->andThrow(new \RuntimeException('diagnostic-log-unavailable'));
        $manager = Mockery::mock(LogManager::class, [$this->app])->makePartial();
        $manager->shouldReceive('channel')->with('api_latency')->andReturn($logger);
        Log::swap($manager);
        config(['ai-assistant.status_snapshot_release' => str_repeat('a', 40)]);
        try {
            $result = app(AssistantStatusSnapshotFootprint::class)->capture(
                static fn (): array => $connection->select('SELECT id FROM public.projects WHERE id = ?', [-987654321]),
            );
            self::assertSame([], $result['value']);
            self::assertSame(['public.projects'], $result['relations']);
            self::assertArrayNotHasKey('reason', $result);
            self::assertSame(1, $explains);
            self::assertSame($dispatcher, $connection->getEventDispatcher());
            self::assertNotNull($logged);
            self::assertSame(str_repeat('a', 40), $logged['release_sha']);
            self::assertGreaterThanOrEqual(500, $logged['select_elapsed_ms']);
            self::assertIsFloat($logged['planning_ms']);
            self::assertSame(1, $logged['bindings_count']);
            self::assertSame(1, $logged['plan_ordinal']);
            self::assertGreaterThan(0, $logged['plan_bytes']);
            foreach (array_diff_key($logged, ['release_sha' => true]) as $value) {
                self::assertTrue(is_int($value) || is_float($value) || is_bool($value) || $value === null);
            }
            self::assertStringNotContainsString('987654321', json_encode($logged, JSON_THROW_ON_ERROR));
        } finally {
            Log::swap($originalLog);
            $connection->setEventDispatcher($original);
        }
        self::assertSame($original, $connection->getEventDispatcher());
    }
}
