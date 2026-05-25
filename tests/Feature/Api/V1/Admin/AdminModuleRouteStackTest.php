<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Http\Middleware\NormalizeAdminResponse;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminModuleRouteStackTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const ADMIN_PREFIXES = [
        'advanced-warehouse',
        'ai-assistant',
        'change-management',
        'executive-documentation',
        'handover-acceptance',
        'machinery-operations',
        'payments',
        'procurement',
        'production-labor',
        'quality-control',
        'safety-management',
        'site-requests',
        'warehouses',
        'workforce',
    ];

    public function refreshDatabase(): void
    {
    }

    public function test_admin_module_routes_use_common_admin_stack(): void
    {
        foreach (self::ADMIN_PREFIXES as $prefix) {
            $routes = $this->routesByPrefix($prefix);

            $this->assertNotEmpty($routes, "Не найдены маршруты для префикса api/v1/admin/{$prefix}");

            foreach ($routes as $route) {
                $middleware = $route->gatherMiddleware();
                $routeDescriptor = implode('|', $route->methods()) . ' ' . $route->uri();

                $this->assertHasAnyMiddleware(
                    $middleware,
                    ['api'],
                    "{$routeDescriptor}: отсутствует middleware api"
                );
                $this->assertHasAnyMiddleware(
                    $middleware,
                    ['admin.response', NormalizeAdminResponse::class],
                    "{$routeDescriptor}: отсутствует middleware NormalizeAdminResponse/admin.response"
                );
                $this->assertHasAnyMiddleware(
                    $middleware,
                    ['authorize:admin.access', AuthorizeMiddleware::class . ':admin.access'],
                    "{$routeDescriptor}: отсутствует middleware authorizе:admin.access"
                );
                $this->assertHasAnyMiddleware(
                    $middleware,
                    ['interface:admin', InterfaceMiddleware::class . ':admin'],
                    "{$routeDescriptor}: отсутствует middleware interface:admin"
                );
            }
        }
    }

    /**
     * @return list<LaravelRoute>
     */
    private function routesByPrefix(string $prefix): array
    {
        $target = "api/v1/admin/{$prefix}";

        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), $target)
        ));
    }

    /**
     * @param list<string> $middleware
     * @param list<string> $accepted
     */
    private function assertHasAnyMiddleware(array $middleware, array $accepted, string $message): void
    {
        foreach ($accepted as $value) {
            if (in_array($value, $middleware, true)) {
                return;
            }
        }

        $this->fail($message . '; фактический стек: ' . implode(', ', $middleware));
    }
}
