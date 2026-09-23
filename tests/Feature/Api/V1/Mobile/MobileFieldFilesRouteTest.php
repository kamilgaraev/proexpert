<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileFieldFilesRouteTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_mobile_field_file_routes_are_registered_inside_mobile_auth_boundary(): void
    {
        $expected = [
            'GET' => 'api/v1/mobile/files',
            'POST' => 'api/v1/mobile/files',
            'GET_SHOW' => 'api/v1/mobile/files/{fileId}',
        ];
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => in_array($route->uri(), array_values($expected), true)
        ));

        $this->assertCount(3, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth:api_mobile', $route->gatherMiddleware());
            $this->assertContains('auth.jwt:api_mobile', $route->gatherMiddleware());
            $this->assertContains('organization.context', $route->gatherMiddleware());
            $this->assertContains('can:access-mobile-app', $route->gatherMiddleware());
        }

        $show = collect($routes)->first(static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/mobile/files/{fileId}');
        $this->assertNotNull($show);
        $this->assertSame('[0-9]+', $show->wheres['fileId'] ?? null);
    }
}
