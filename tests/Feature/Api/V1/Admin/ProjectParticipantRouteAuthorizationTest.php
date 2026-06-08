<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProjectParticipantRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_project_participant_routes_require_project_context(): void
    {
        $routes = $this->participantRoutes();

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains('project.context', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_project_participant_mutations_require_manage_permission(): void
    {
        $routes = $this->participantRoutes();

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            foreach ($methods as $method) {
                if (! $this->requiresManagePermission($method, $route->uri())) {
                    continue;
                }

                $this->assertContains(
                    'authorize:projects.organizations.manage',
                    $route->gatherMiddleware(),
                    "{$method} {$route->uri()}"
                );
            }
        }
    }

    /**
     * @return array<int, LaravelRoute>
     */
    private function participantRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/v1/admin/projects/{project}/participants')
                || str_starts_with($route->uri(), 'api/v1/admin/projects/{project}/organizations')
                || str_starts_with($route->uri(), 'api/v1/admin/projects/{project}/participant-invitations')
                || $route->uri() === 'api/v1/admin/projects/{project}/available-organizations'
        ));
    }

    private function requiresManagePermission(string $method, string $uri): bool
    {
        if ($uri === 'api/v1/admin/projects/{project}/available-organizations') {
            return true;
        }

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
