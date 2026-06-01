<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProjectEventRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_project_event_routes_require_schedule_permissions(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => str_starts_with(
                $route->uri(),
                'api/v1/admin/projects/{project}/events'
            )
        ));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $middleware = $route->gatherMiddleware();

            foreach ($methods as $method) {
                $this->assertContains(
                    $this->expectedAuthorizeMiddleware($method),
                    $middleware,
                    "{$method} {$route->uri()}"
                );
            }
        }
    }

    private function expectedAuthorizeMiddleware(string $method): string
    {
        if ($method === 'GET') {
            return 'authorize:schedule.view,project,project';
        }

        if ($method === 'POST') {
            return 'authorize:schedule.create,project,project';
        }

        return 'authorize:schedule.edit,project,project';
    }
}
