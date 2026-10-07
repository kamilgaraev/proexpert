<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Http\Middleware\RecordApiResponseTime;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ApiQueryMetricsTest extends TestCase
{
    public function test_snapshot_diagnostics_are_private_bounded_and_do_not_leak_to_the_next_request(): void
    {
        $request = Request::create('/api/v1/admin/ai-assistant/rag/status', 'GET');
        $this->app->instance('request', $request);
        config(['ai-assistant.status_snapshot_release' => str_repeat('a', 40),
            'cache.default' => 'redis', 'cache.stores.redis.driver' => 'redis', 'cache.stores.redis.connection' => 'cache',
            'database.redis.cache.password' => 'private-redis-secret',
            'database.redis.cache.url' => 'redis://codex:private-url-secret@cache-host:6379/1']);
        $captured = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('info')->twice()->with('api_response_timing', Mockery::on(static function (array $context) use (&$captured): bool {
            $captured[] = $context;

            return true;
        }));
        Log::shouldReceive('channel')->with('api_latency')->twice()->andReturn($logger);
        (new RecordApiResponseTime)->handle($request, static function (): Response {
            AssistantStatusSnapshotDiagnostics::request('snapshot_missing', 'sources', 'private-cache-key', null, true);
            $first = request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['assistant_snapshot'];
            config(['database.redis.cache.password' => 'different-private-secret',
                'database.redis.cache.url' => 'redis://changed:other-private-secret@cache-host:6379/1']);
            AssistantStatusSnapshotDiagnostics::request('snapshot_missing', 'sources', 'private-cache-key', null, true);
            self::assertSame($first['cache_namespace_hash'], request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['assistant_snapshot']['cache_namespace_hash']);
            config(['database.redis.cache.url' => 'redis://cache-host:6379/1?database=2']);
            AssistantStatusSnapshotDiagnostics::request('snapshot_missing', 'sources', 'private-cache-key', null, true);
            $second = request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['assistant_snapshot'];
            self::assertNotSame($first['cache_namespace_hash'], $second['cache_namespace_hash']);
            config(['database.redis.cache.options.prefix' => 'separate-namespace']);
            AssistantStatusSnapshotDiagnostics::request('snapshot_missing', 'sources', 'private-cache-key', null, true);
            self::assertNotSame($second['cache_namespace_hash'], request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['assistant_snapshot']['cache_namespace_hash']);
            config(['queue.connections.redis.queue' => 'other-default-queue']);
            AssistantStatusSnapshotDiagnostics::request('snapshot_missing', 'sources', 'private-cache-key', null, true);
            self::assertSame($first['queue_namespace_hash'], request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['assistant_snapshot']['queue_namespace_hash']);

            return new Response('private body');
        });
        (new RecordApiResponseTime)->handle($request, static fn (): Response => new Response);

        self::assertSame('snapshot_missing', $captured[0]['assistant_snapshot']['phase']);
        self::assertTrue($captured[0]['assistant_snapshot']['refresh_queued']);
        self::assertArrayNotHasKey('assistant_snapshot', $captured[1]);
        self::assertFalse($request->attributes->has(ApiQueryMetrics::REQUEST_ATTRIBUTE));
        foreach (['private-', 'cache-host', 'codex', 'changed'] as $private) {
            self::assertStringNotContainsString($private, json_encode($captured, JSON_THROW_ON_ERROR));
        }
    }

    public function test_sql_events_are_counted_only_during_the_current_http_operation(): void
    {
        $request = Request::create('/api/private', 'GET');
        $this->app->instance('request', $request);
        $captured = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('info')->twice()->with('api_response_timing', Mockery::on(static function (array $context) use (&$captured): bool {
            $captured[] = $context;

            return true;
        }));
        Log::shouldReceive('channel')->with('api_latency')->twice()->andReturn($logger);
        $event = new QueryExecuted('select secret_value', ['private binding'], 12.5, DB::connection());
        Event::dispatch($event);

        (new RecordApiResponseTime)->handle($request, static function () use ($event): Response {
            Event::dispatch($event);
            Event::dispatch($event);

            return new Response('private body');
        });
        Event::dispatch($event);
        (new RecordApiResponseTime)->handle($request, static fn (): Response => new Response);

        $this->assertSame(2, $captured[0]['sql_count']);
        $this->assertSame(25.0, $captured[0]['sql_total_ms']);
        $this->assertSame(12.5, $captured[0]['sql_max_ms']);
        $this->assertSame(['other' => ['count' => 2, 'total_ms' => 25.0]], $captured[0]['sql_groups']);
        $this->assertSame(0, $captured[1]['sql_count']);
        $this->assertFalse($request->attributes->has(ApiQueryMetrics::REQUEST_ATTRIBUTE));
        $this->assertStringNotContainsString('secret_value', json_encode($captured));
        $this->assertStringNotContainsString('private', json_encode($captured));
    }
}
