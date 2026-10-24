<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantLowerEnvironmentProfiler;
use Fiber;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class OrdinaryAssistantLowerEnvironmentProfilerTest extends TestCase
{
    #[DataProvider('disabledConfigurations')]
    public function test_disabled_and_production_never_attach_or_emit(bool $enabled, string $environment): void
    {
        Log::shouldReceive('info', 'debug', 'error')->never();
        $connection = DB::connection('pgsql');
        $original = $connection->getEventDispatcher();
        $profiler = new OrdinaryAssistantLowerEnvironmentProfiler($enabled, $environment);
        $result = new \stdClass;
        $calls = 0;
        self::assertSame($result, $profiler->run('freshness_scope', function () use ($connection, $original, $result, &$calls): object {
            $calls++;
            self::assertSame($original, $connection->getEventDispatcher());

            return $result;
        }));
        self::assertSame(1, $calls);
        self::assertSame([], $profiler->snapshot());
        self::assertSame($original, $connection->getEventDispatcher());
    }

    public static function disabledConfigurations(): array
    {
        return [[false, 'local'], [false, 'testing'], [true, 'production'], [true, 'prod'], [true, 'unknown']];
    }

    public function test_nested_mixed_sources_count_each_query_only_in_its_innermost_category(): void
    {
        $connection = DB::connection('pgsql');
        $original = $connection->getEventDispatcher();
        $foreignEvents = 0;
        $original->listen(QueryExecuted::class, function () use (&$foreignEvents): void {
            $foreignEvents++;
        });
        $profiler = new OrdinaryAssistantLowerEnvironmentProfiler(true, 'local');
        $read = fn () => $connection->select('select cast(? as text)', ['PRIVATE_SQL_BINDING_CONTENT_159_219753']);
        $profiler->run('freshness_scope', function () use ($profiler, $read): void {
            $read();
            $profiler->run('reviewer_user_membership', $read);
            $profiler->run('approved_sql_source_capture', $read);
            $profiler->run('approved_document_source_capture', fn () => $profiler->run('document_corpus', $read));
            $read();
        });
        $snapshot = $profiler->snapshot();
        self::assertSame(2, $snapshot['freshness_scope']['query_count']);
        self::assertSame(1, $snapshot['reviewer_user_membership']['query_count']);
        self::assertSame(1, $snapshot['approved_sql_source_capture']['query_count']);
        self::assertSame(0, $snapshot['approved_document_source_capture']['query_count']);
        self::assertSame(1, $snapshot['document_corpus']['query_count']);
        self::assertSame(5, array_sum(array_column($snapshot, 'query_count')));
        self::assertSame(5, $foreignEvents);
        self::assertSame($original, $connection->getEventDispatcher());
        $read();
        self::assertSame(6, $foreignEvents);
        self::assertSame($snapshot, $profiler->snapshot());
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE', $serialized);
        self::assertStringNotContainsString('cast', $serialized);
        foreach ($snapshot as $record) {
            self::assertSame(['count', 'total_ms', 'max_ms', 'query_count', 'query_total_ms', 'query_max_ms'], array_keys($record));
            self::assertSame(1, $record['count']);
            self::assertGreaterThanOrEqual($record['max_ms'], $record['total_ms']);
            self::assertGreaterThanOrEqual($record['query_max_ms'], $record['query_total_ms']);
        }
    }

    public function test_exception_identity_and_listener_cleanup_survive_nested_failure(): void
    {
        $connection = DB::connection('pgsql');
        $original = $connection->getEventDispatcher();
        $profiler = new OrdinaryAssistantLowerEnvironmentProfiler(true, 'testing');
        $failure = new RuntimeException('PRIVATE_FAILURE_CONTENT');
        $calls = 0;
        try {
            $profiler->run('freshness_scope', function () use ($profiler, $connection, $failure, &$calls): void {
                $profiler->run('retrieval', function () use ($connection, $failure, &$calls): void {
                    $calls++;
                    $connection->select('select 1');
                    throw $failure;
                });
            });
            self::fail('The operation must throw.');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(1, $calls);
        self::assertSame($original, $connection->getEventDispatcher());
        self::assertSame(1, $profiler->snapshot()['retrieval']['query_count']);
        self::assertStringNotContainsString('PRIVATE', json_encode($profiler->snapshot(), JSON_THROW_ON_ERROR));
        $next = new OrdinaryAssistantLowerEnvironmentProfiler(true, 'testing');
        $next->run('projection/reference_guard', fn () => $connection->select('select 1'));
        self::assertSame(1, $next->snapshot()['projection/reference_guard']['query_count']);
        self::assertSame(1, $profiler->snapshot()['retrieval']['query_count']);
        self::assertSame($original, $connection->getEventDispatcher());
    }

    public function test_other_fibers_cannot_attach_or_contribute_to_an_active_profile(): void
    {
        $connection = DB::connection('pgsql');
        $original = $connection->getEventDispatcher();
        $first = new OrdinaryAssistantLowerEnvironmentProfiler(true, 'testing');
        $second = new OrdinaryAssistantLowerEnvironmentProfiler(true, 'testing');
        $fiber = new Fiber(fn () => $first->run('retrieval', function () use ($connection): void {
            Fiber::suspend();
            $connection->select('select 1');
        }));
        $fiber->start();
        $second->run('retrieval', fn () => $connection->select('select 1'));
        self::assertSame([], $second->snapshot());
        $fiber->resume();
        self::assertSame(1, $first->snapshot()['retrieval']['query_count']);
        self::assertSame($original, $connection->getEventDispatcher());
    }
}
