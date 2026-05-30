<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProjectScheduleRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_project_schedule_routes_require_action_permissions(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => str_starts_with(
                $route->uri(),
                'api/v1/admin/projects/{project}/schedules'
            )
        ));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $middleware = $route->gatherMiddleware();

            foreach ($methods as $method) {
                $this->assertContains(
                    $this->expectedAuthorizeMiddleware($method, $route->uri()),
                    $middleware,
                    "{$method} {$route->uri()}"
                );
            }
        }
    }

    private function expectedAuthorizeMiddleware(string $method, string $uri): string
    {
        if ($method === 'GET') {
            $permission = str_ends_with($uri, '/export') ? 'schedule.export' : 'schedule.view';

            return "authorize:{$permission},project,project";
        }

        if ($method === 'POST' && ($uri === 'api/v1/admin/projects/{project}/schedules' || str_ends_with($uri, '/from-estimate'))) {
            return 'authorize:schedule.create,project,project';
        }

        if ($method === 'DELETE' && $uri === 'api/v1/admin/projects/{project}/schedules/{schedule}') {
            return 'authorize:schedule.delete,project,project';
        }

        return 'authorize:schedule.edit,project,project';
    }
}
