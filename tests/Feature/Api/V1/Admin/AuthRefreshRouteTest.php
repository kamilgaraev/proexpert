<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthRefreshRouteTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_admin_refresh_route_allows_expired_jwt_to_reach_controller(): void
    {
        $route = Route::getRoutes()->getByName('admin.auth.refresh');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/admin/auth/refresh', $route->uri());
        $this->assertSame(['POST'], $route->methods());

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth.jwt:api_admin', $middleware);
        $this->assertNotContains('auth:api_admin', $middleware);
    }

    public function test_admin_me_and_logout_remain_fully_authenticated(): void
    {
        foreach (['admin.auth.me', 'admin.auth.logout'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:api_admin', $middleware);
            $this->assertContains('auth.jwt:api_admin', $middleware);
        }
    }
}
