<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class UserManagementRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_role_management_routes_use_existing_assign_roles_permission(): void
    {
        $routes = $this->roleManagementRoutes();

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'authorize:users.assign_roles,organization',
                $middleware,
                $route->uri()
            );
            $this->assertNotContains(
                'authorize:users.manage_roles,organization',
                $middleware,
                $route->uri()
            );
        }
    }

    /**
     * @return array<int, LaravelRoute>
     */
    private function roleManagementRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static function (LaravelRoute $route): bool {
                $uri = $route->uri();

                $isCustomRoleAssignmentRoute = str_starts_with($uri, 'api/v1/landing/user-management/custom-roles/')
                    && (str_ends_with($uri, '/assign') || str_ends_with($uri, '/unassign'));

                $isOrganizationUserRoleRoute = str_starts_with($uri, 'api/v1/landing/user-management/organization-users/')
                    && (
                        str_ends_with($uri, '/roles')
                        || str_ends_with($uri, '/grant-owner')
                        || str_contains($uri, '/assign-role/')
                        || str_contains($uri, '/unassign-role/')
                    );

                return $isCustomRoleAssignmentRoute || $isOrganizationUserRoleRoute;
            }
        ));
    }
}
