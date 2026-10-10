<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdminBoundaryProcessingTimingsTest extends TestCase
{
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
        $middleware = new class($service) extends InterfaceMiddleware {
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
