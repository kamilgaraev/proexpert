<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignPackageCollection;
use App\BusinessModules\Features\Procurement\Http\Middleware\EnsureProcurementActive;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\NormalizeAdminResponse;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdminBoundaryProcessingTimingsTest extends TestCase
{
    public function test_design_serialization_phases_are_exact_get_opt_in_and_keep_response_bytes(): void
    {
        $expected = null;
        foreach ([
            ['/api/v1/admin/design-management/packages', 'GET', true],
            ['/api/v1/admin/design-management/packages/1', 'GET', true],
            ['/api/v1/admin/design-management/packages', 'POST', true],
            ['/api/v1/admin/design-management/packages', 'GET', false],
        ] as [$path, $method, $capture]) {
            $request = Request::create($path, $method);
            $metrics = new ApiQueryMetrics($capture);
            $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
            $this->app->instance('request', $request);
            $response = AdminResponse::paginated(new DesignPackageCollection(collect()), ['total' => 0], 'fixture', 201);
            $expected ??= $response->getContent();
            self::assertSame($expected, $response->getContent());
            self::assertSame(201, $response->getStatusCode());
            $phases = $metrics->summary()['processing_phases'] ?? [];
            if ($path !== '/api/v1/admin/design-management/packages' || $method !== 'GET' || ! $capture) {
                self::assertSame([], $phases);
                continue;
            }
            self::assertSame(['design_freshness', 'design_resources', 'design_response_build', 'design_response_decode', 'request_render', 'list_encode'], array_keys($phases));
            foreach ($phases as $phase) {
                self::assertSame(1, $phase['count']);
                self::assertSame(0, $phase['sql_count']);
                foreach ($phase as $value) {
                    self::assertTrue(is_int($value) || is_float($value));
                }
            }
            self::assertStringNotContainsString('fixture', json_encode($phases, JSON_THROW_ON_ERROR));
        }
    }

    public function test_design_response_build_and_decode_keep_separate_sql_deltas(): void
    {
        $request = Request::create('/api/v1/admin/design-management/packages');
        $metrics = new ApiQueryMetrics(true);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $this->app->instance('request', $request);
        $collection = new class(collect()) extends ResourceCollection
        {
            public function response($request = null): JsonResponse
            {
                ApiQueryMetrics::record(app('request'), 2.5);

                return new class(['data' => [['nested' => [null, false, 2.5, 'МОСТ']]]]) extends JsonResponse
                {
                    public function getData($assoc = false, $depth = 512): mixed
                    {
                        ApiQueryMetrics::record(app('request'), 4.5);

                        return parent::getData($assoc, $depth);
                    }
                };
            }
        };
        $response = AdminResponse::paginated($collection, ['total' => 1]);
        self::assertSame([['nested' => [null, false, 2.5, 'МОСТ']]], $response->getData(true)['data']);
        foreach (['design_response_build' => [1, 2.5], 'design_response_decode' => [1, 4.5], 'request_render' => [2, 7.0]] as $name => [$count, $total]) {
            $phase = $metrics->summary()['processing_phases'][$name];
            self::assertSame(1, $phase['count']);
            self::assertSame($count, $phase['sql_count']);
            self::assertSame($total, $phase['sql_total_ms']);
        }
    }

    public function test_design_response_phases_close_and_preserve_build_and_decode_exceptions(): void
    {
        foreach (['build', 'decode'] as $failure) {
            $request = Request::create('/api/v1/admin/design-management/packages');
            $metrics = new ApiQueryMetrics(true);
            $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
            $this->app->instance('request', $request);
            $sentinel = new RuntimeException('private-fixture');
            $collection = Mockery::mock(ResourceCollection::class);
            if ($failure === 'build') {
                $collection->shouldReceive('response')->once()->andThrow($sentinel);
            } else {
                $response = Mockery::mock(JsonResponse::class);
                $response->shouldReceive('getData')->once()->with(true)->andThrow($sentinel);
                $collection->shouldReceive('response')->once()->andReturn($response);
            }
            try {
                AdminResponse::paginated($collection, []);
                self::fail('expected serialization failure');
            } catch (RuntimeException $exception) {
                self::assertSame($sentinel, $exception);
            }
            $phases = $metrics->summary()['processing_phases'];
            self::assertSame(1, $phases['design_response_build']['count']);
            self::assertSame(1, $phases['request_render']['count']);
            self::assertSame($failure === 'decode', isset($phases['design_response_decode']));
            self::assertArrayNotHasKey('list_encode', $phases);
            self::assertStringNotContainsString('private-fixture', json_encode($phases, JSON_THROW_ON_ERROR));
        }
    }

    public function test_authorization_timers_keep_each_permission_check_and_original_response(): void
    {
        foreach (['admin.access' => 'admin_authorize', 'procurement.purchase_requests.view' => 'purchase_authorize'] as $permission => $phase) {
            $request = $this->request();
            $user = new User(['current_organization_id' => 202]);
            $request->setUserResolver(fn () => $user);
            $service = Mockery::mock(AuthorizationService::class);
            $service->shouldReceive('can')->once()->with($user, $permission, ['organization_id' => 202])->andReturnTrue();
            $original = new Response('passed', 201);
            $result = (new AuthorizeMiddleware($service))->handle($request, fn () => $original, $permission);

            self::assertSame($original, $result);
            self::assertSame(1, $this->phases($request)[$phase]['count']);
        }
    }

    public function test_interface_timer_keeps_access_check_and_response(): void
    {
        $request = $this->request();
        $user = new User;
        $request->setUserResolver(fn () => $user);
        $service = Mockery::mock(AuthorizationService::class);
        $service->shouldReceive('canAccessInterface')->once()->with($user, 'admin', null)->andReturnTrue();
        $middleware = new class($service) extends InterfaceMiddleware
        {
            protected function resolveContext(Request $request, ?string $contextType, ?string $contextParam): ?AuthorizationContext
            {
                return null;
            }
        };
        $original = new Response('passed');

        self::assertSame($original, $middleware->handle($request, fn () => $original, 'admin'));
        self::assertSame(1, $this->phases($request)['interface_access']['count']);
    }

    public function test_module_timer_keeps_both_checks_and_denied_dependencies(): void
    {
        foreach ([[true, true, null], [false, false, 'MODULE_NOT_ACTIVE'], [true, false, 'DEPENDENCY_NOT_ACTIVE']] as [$procurement, $warehouse, $error]) {
            $request = $this->request();
            $access = Mockery::mock(AccessController::class);
            $access->shouldReceive('hasModuleAccess')->once()->with(202, 'procurement')->andReturn($procurement);
            if ($procurement) {
                $access->shouldReceive('hasModuleAccess')->once()->with(202, 'basic-warehouse')->andReturn($warehouse);
            }
            $this->app->instance(AccessController::class, $access);
            $original = new Response('passed');
            $response = (new EnsureProcurementActive)->handle($request, fn () => $original);
            if ($error === null) {
                self::assertSame($original, $response);
                self::assertSame(1, $this->phases($request)['procurement_modules']['count']);
            } else {
                self::assertSame(403, $response->getStatusCode());
                self::assertInstanceOf(JsonResponse::class, $response);
                self::assertSame($error, $response->getData(true)['error_code']);
                self::assertArrayNotHasKey('procurement_modules', $this->phases($request));
            }
        }
    }

    public function test_normalization_timer_preserves_payload_status_headers_cookies_and_no_cache(): void
    {
        $request = $this->request();
        $original = AdminResponse::paginated([['id' => 1, 'nested' => ['label' => 'fixture']]], ['total' => 1], null, 201);
        $original->headers->set('X-Fixture', 'preserved');
        $original->headers->setCookie(Cookie::create('fixture', 'opaque'));
        $expected = $original->getData(true);
        $response = (new NormalizeAdminResponse)->handle($request, fn () => $original);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($expected, $response->getData(true));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('preserved', $response->headers->get('X-Fixture'));
        self::assertSame('opaque', $response->headers->getCookies()[0]->getValue());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
        self::assertSame(1, $this->phases($request)['response_normalize']['count']);
        self::assertSame(0, $this->phases($request)['response_normalize']['sql_count']);
        self::assertSame(0.0, $this->phases($request)['response_normalize']['sql_total_ms']);
    }

    public function test_normalization_failure_is_timed_and_the_exception_is_preserved(): void
    {
        $request = $this->request();
        $original = Mockery::mock(JsonResponse::class);
        $original->shouldReceive('getStatusCode')->once()->andReturn(200);
        $original->shouldReceive('getData')->once()->with(true)->andThrow(new RuntimeException('fixture failure'));
        try {
            (new NormalizeAdminResponse)->handle($request, fn () => $original);
            self::fail('Expected normalization failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('fixture failure', $exception->getMessage());
            self::assertSame(1, $this->phases($request)['response_normalize']['count']);
            self::assertFalse($request->attributes->has(AdminResponse::NORMALIZATION_ATTRIBUTE));
            self::assertFalse($request->attributes->has(AdminResponse::DECODED_ARRAY_ATTRIBUTE));
        }
    }

    public function test_pir_encoding_and_normalization_phases_do_not_overlap(): void
    {
        $request = Request::create('/api/v1/admin/design-management/packages', 'GET');
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, new ApiQueryMetrics(true));
        $request->attributes->set(AdminResponse::NORMALIZATION_ATTRIBUTE, false);
        $request->attributes->set(AdminResponse::DECODED_ARRAY_ATTRIBUTE, 'previous');
        $this->app->instance('request', $request);

        $original = AdminResponse::paginated([['id' => 1]], ['total' => 1]);
        $expected = $original->getContent();
        $response = (new NormalizeAdminResponse)->handle($request, fn () => $original);

        self::assertSame($expected, $response->getContent());
        self::assertSame(1, $this->phases($request)['list_encode']['count']);
        self::assertSame(1, $this->phases($request)['response_normalize']['count']);
        self::assertFalse($request->attributes->get(AdminResponse::NORMALIZATION_ATTRIBUTE));
        self::assertSame('previous', $request->attributes->get(AdminResponse::DECODED_ARRAY_ATTRIBUTE));
    }

    public function test_custom_response_payload_keeps_recursive_conversion_and_restores_native_marker(): void
    {
        $request = $this->request();
        $request->attributes->set(AdminResponse::DECODED_ARRAY_ATTRIBUTE, true);
        $this->app->instance('request', $request);
        $dto = Mockery::mock(Arrayable::class);
        $dto->shouldReceive('toArray')->once()->andReturn(['fixture' => true]);
        $original = Mockery::mock(JsonResponse::class)->makePartial();
        $original->shouldReceive('getData')->once()->with(true)->andReturn(['success' => true, 'data' => [['nested' => $dto]], 'meta' => ['total' => 1]]);
        $original->shouldReceive('getStatusCode')->andReturn(200);
        $original->headers = new \Symfony\Component\HttpFoundation\ResponseHeaderBag;

        $response = (new NormalizeAdminResponse)->handle($request, fn () => $original);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame([['nested' => ['fixture' => true]]], $response->getData(true)['data']);
        self::assertSame(['total' => 1], $response->getData(true)['meta']);
        self::assertTrue($request->attributes->get(AdminResponse::DECODED_ARRAY_ATTRIBUTE));
    }

    public function test_native_standard_success_preserves_empty_object_maps_and_lists(): void
    {
        $request = $this->request();
        $this->app->instance('request', $request);
        $data = ['totals' => new \stdClass(), 'items' => [], 'nested' => [['map' => new \stdClass()]]];

        foreach ([
            AdminResponse::success($data, 'fixture', 201),
            AdminResponse::paginated([$data], ['total' => 1], 'fixture', 201),
        ] as $original) {
            $response = (new NormalizeAdminResponse)->handle($request, fn () => $original);

            self::assertInstanceOf(JsonResponse::class, $response);
            self::assertSame($original->getContent(), $response->getContent());
            $body = $response->getData();
            $row = is_array($body->data) ? $body->data[0] : $body->data;
            self::assertInstanceOf(\stdClass::class, $row->totals);
            self::assertSame([], $row->items);
            self::assertInstanceOf(\stdClass::class, $row->nested[0]->map);
        }
    }

    public function test_native_normalization_preserves_nested_scalar_and_pagination_payloads(): void
    {
        $request = $this->request();
        $this->app->instance('request', $request);
        $data = [['nested' => ['empty' => [], 'null' => null, 'boolean' => false, 'float' => 2.5, 'unicode' => 'МОСТ']]];
        foreach ([new JsonResponse(['data' => $data, 'current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2, 'next_page_url' => '/fixture']), AdminResponse::paginated($data, ['total' => 2], 'fixture', 201, ['count' => 1], ['next' => '/fixture'])] as $original) {
            $response = (new NormalizeAdminResponse)->handle($request, fn () => $original);
            self::assertInstanceOf(JsonResponse::class, $response);
            self::assertSame($data, $response->getData(true)['data']);
            self::assertSame(2, $response->getData(true)['meta']['total']);
            self::assertSame($original->getStatusCode(), $response->getStatusCode());
            self::assertFalse($request->attributes->has(AdminResponse::DECODED_ARRAY_ATTRIBUTE));
        }
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/admin/procurement/purchase-requests', 'GET');
        $request->attributes->set('current_organization_id', 202);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, new ApiQueryMetrics(true));

        return $request;
    }

    private function phases(Request $request): array
    {
        return $request->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['processing_phases'];
    }
}
