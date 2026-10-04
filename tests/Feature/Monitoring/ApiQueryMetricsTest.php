<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Http\Middleware\RecordApiResponseTime;
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
