<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Http\Middleware\RecordApiResponseTime;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecordApiResponseTimeTest extends TestCase
{
    public function test_query_metrics_are_numeric_request_local_and_restored_after_an_exception(): void
    {
        $request = Request::create('/api/private', 'GET');
        $previous = new ApiQueryMetrics;
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $previous);
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        try {
            (new RecordApiResponseTime)->handle($request, static function () use ($request): never {
                ApiQueryMetrics::record($request, 12.345);
                ApiQueryMetrics::record($request, 3.5);
                ApiQueryMetrics::record($request, NAN);
                ApiQueryMetrics::record($request, -1);
                throw new NotFoundHttpException('private');
            });
            self::fail('Expected an HTTP exception.');
        } catch (NotFoundHttpException) {
            self::assertSame(2, $captured['sql_count']);
            self::assertSame(15.85, $captured['sql_total_ms']);
            self::assertSame(12.35, $captured['sql_max_ms']);
            self::assertSame($previous, $request->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE));
            self::assertSame(0, $previous->summary()['sql_count']);
        }
    }

    public function test_query_groups_use_fixed_names_and_never_include_sql_or_bindings(): void
    {
        $request = Request::create('/api/private', 'GET');
        $metrics = new ApiQueryMetrics;
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $connection = new \Illuminate\Database\Connection(null);
        $queries = [
            'role_conditions' => 'select * from "role_conditions" where private = ?',
            'contexts' => 'select * from "authorization_contexts" where private = ?',
            'schema' => 'select private from pg_attribute a',
            'rag_counts' => 'select count(*) as stored_count from private_table',
            'rag_sources' => 'select * from "ai_rag_sources" where private = ?',
            'documents' => 'select * from "files" where private = ?',
            'settings' => 'SET LOCAL statement_timeout = 123',
            'other' => 'select private from secret_table',
        ];
        foreach ($queries as $sql) {
            ApiQueryMetrics::recordQuery($request, new \Illuminate\Database\Events\QueryExecuted($sql, ['private token'], 2.5, $connection));
        }
        ApiQueryMetrics::recordQuery($request, new \Illuminate\Database\Events\QueryExecuted('private', [], NAN, $connection));
        $summary = $metrics->summary();
        self::assertSame(8, $summary['sql_count']);
        self::assertSame(20.0, $summary['sql_total_ms']);
        self::assertSame(array_keys($queries), array_keys($summary['sql_groups']));
        foreach ($summary['sql_groups'] as $group) {
            self::assertSame(['count' => 1, 'total_ms' => 2.5], $group);
        }
        self::assertStringNotContainsString('private', json_encode($summary, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret_table', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function test_source_diagnostics_are_limited_to_selected_assistant_get_routes(): void
    {
        foreach ([['GET', '/api/v1/admin/ai-assistant/conversations', true], ['POST', '/api/v1/admin/ai-assistant/conversations', false],
            ['GET', '/api/v1/admin/ai-assistant/rag/status', true], ['POST', '/api/v1/admin/ai-assistant/rag/status', false],
            ['GET', '/api/v1/admin/ai-assistant/rag/status/detail', false],
            ['GET', '/api/v1/admin/ai-assistant/conversations/1', false], ['GET', '/api/v1/mobile/ai-assistant/conversations', false]] as [$method, $path, $expected]) {
            Facade::clearResolvedInstances();
            $captured = null;
            $this->expectLog(static function (array $context) use (&$captured): bool {
                $captured = $context;

                return true;
            });
            $request = Request::create($path.'?token=private', $method);
            (new RecordApiResponseTime)->handle($request, static function () use ($request): Response {
                ApiQueryMetrics::record($request, 2.5);

                return new Response('ok');
            });
            self::assertSame($expected, array_key_exists('sql_sources', $captured));
            self::assertStringNotContainsString('private', json_encode($captured, JSON_THROW_ON_ERROR));
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_it_records_every_api_response_with_a_route_template_and_no_request_values(): void
    {
        $request = Request::create('/api/v1/admin/projects/123?token=private', 'GET');
        $request->setRouteResolver(static fn (): Route => new Route('GET', 'api/v1/admin/projects/{project}', static fn (): null => null));
        $traceId = str_repeat('a', 32);
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        $response = (new RecordApiResponseTime)->handle($request, static function () use ($traceId): Response {
            $response = new Response('ok');
            $response->headers->set('X-Trace-ID', $traceId);

            return $response;
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('api/v1/admin/projects/{project}', $captured['route']);
        self::assertSame('GET', $captured['method']);
        self::assertSame(200, $captured['status_code']);
        self::assertSame($traceId, $captured['trace_id']);
        self::assertGreaterThanOrEqual(0, $captured['duration_ms']);
        self::assertStringNotContainsString('123', json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($captured, JSON_THROW_ON_ERROR));
    }

    public function test_it_records_api_exceptions_without_a_raw_uri(): void
    {
        $request = Request::create('/api/v1/admin/missing/123?token=private', 'GET');
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        try {
            (new RecordApiResponseTime)->handle($request, static function (): never {
                throw new NotFoundHttpException('private');
            });
            self::fail('Expected an HTTP exception.');
        } catch (NotFoundHttpException) {
            self::assertSame('unmatched', $captured['route']);
            self::assertSame(404, $captured['status_code']);
            self::assertStringNotContainsString('private', json_encode($captured, JSON_THROW_ON_ERROR));
        }
    }

    public function test_it_logs_only_the_uuid_from_an_accepted_assistant_chat_response(): void
    {
        $responseRequestId = '1b45f61f-87ea-4fc0-ae72-4369f529e311';
        $request = Request::create('/api/v1/admin/ai-assistant/chat', 'POST', [
            'request_id' => 'b74df874-a0bb-48e6-b036-d0a9f8301396',
            'message' => 'private prompt',
            'organization_id' => 731,
        ]);
        $request->setRouteResolver(static fn (): Route => new Route(
            'POST',
            'api/v1/admin/ai-assistant/chat',
            static fn (): null => null,
        ));
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        $response = (new RecordApiResponseTime)->handle($request, static fn (): JsonResponse => new JsonResponse([
            'success' => true,
            'data' => ['request_id' => $responseRequestId],
        ], 202));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame($responseRequestId, $captured['request_id']);
        self::assertStringNotContainsString('private prompt', json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('organization_id', $captured);
    }

    public function test_it_does_not_log_assistant_request_id_for_nonaccepted_status(): void
    {
        $requestId = '1b45f61f-87ea-4fc0-ae72-4369f529e311';
        $request = Request::create('/api/v1/admin/ai-assistant/chat', 'POST');
        $request->setRouteResolver(static fn (): Route => new Route(
            'POST',
            'api/v1/admin/ai-assistant/chat',
            static fn (): null => null,
        ));
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        (new RecordApiResponseTime)->handle($request, static fn (): JsonResponse => new JsonResponse([
            'success' => true,
            'data' => ['request_id' => $requestId],
        ], 200));

        self::assertArrayNotHasKey('request_id', $captured);
    }

    public function test_it_does_not_log_an_invalid_assistant_request_uuid_or_fall_back_to_request_input(): void
    {
        $requestIdFromInput = 'b74df874-a0bb-48e6-b036-d0a9f8301396';
        $request = Request::create('/api/v1/admin/ai-assistant/chat', 'POST', [
            'request_id' => $requestIdFromInput,
            'message' => 'private prompt',
        ]);
        $request->setRouteResolver(static fn (): Route => new Route(
            'POST',
            'api/v1/admin/ai-assistant/chat',
            static fn (): null => null,
        ));
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        (new RecordApiResponseTime)->handle($request, static fn (): JsonResponse => new JsonResponse([
            'success' => true,
            'data' => ['request_id' => 'invalid-uuid'],
        ], 202));

        self::assertArrayNotHasKey('request_id', $captured);
        self::assertStringNotContainsString($requestIdFromInput, json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private prompt', json_encode($captured, JSON_THROW_ON_ERROR));
    }

    public function test_it_does_not_log_assistant_request_ids_for_other_routes(): void
    {
        $requestId = '1b45f61f-87ea-4fc0-ae72-4369f529e311';
        $request = Request::create('/api/v1/admin/projects', 'POST', ['message' => 'private prompt']);
        $request->setRouteResolver(static fn (): Route => new Route(
            'POST',
            'api/v1/admin/projects',
            static fn (): null => null,
        ));
        $captured = null;
        $this->expectLog(static function (array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

        (new RecordApiResponseTime)->handle($request, static fn (): JsonResponse => new JsonResponse([
            'success' => true,
            'data' => ['request_id' => $requestId],
        ], 202));

        self::assertArrayNotHasKey('request_id', $captured);
        self::assertStringNotContainsString($requestId, json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private prompt', json_encode($captured, JSON_THROW_ON_ERROR));
    }

    public function test_it_skips_cors_preflight(): void
    {
        $logManager = Mockery::mock();
        $logManager->shouldNotReceive('channel');
        $container = new Container;
        $container->instance('log', $logManager);
        Facade::setFacadeApplication($container);

        $response = (new RecordApiResponseTime)->handle(
            Request::create('/api/v1/admin/projects', 'OPTIONS'),
            static fn (): Response => new Response('', 204),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_logging_failure_does_not_change_the_api_response(): void
    {
        $logManager = Mockery::mock();
        $logManager->shouldReceive('channel')->once()->with('api_latency')->andThrow(new RuntimeException('log unavailable'));
        $container = new Container;
        $container->instance('log', $logManager);
        Facade::setFacadeApplication($container);

        $response = (new RecordApiResponseTime)->handle(
            Request::create('/api/v1/admin/projects', 'GET'),
            static fn (): Response => new Response('ok'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function expectLog(callable $check): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('info')->once()->with('api_response_timing', Mockery::on($check));
        $logManager = Mockery::mock();
        $logManager->shouldReceive('channel')->once()->with('api_latency')->andReturn($logger);
        $container = new Container;
        $container->instance('log', $logManager);
        Facade::setFacadeApplication($container);
    }
}
