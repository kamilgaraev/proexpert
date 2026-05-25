<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteRequestsRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_site_requests_admin_routes_require_expected_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/dashboard/statistics', 'site_requests.statistics');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/dashboard/overdue', 'site_requests.statistics');

        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/calendar', 'site_requests.calendar.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/calendar/by-date', 'site_requests.calendar.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/calendar/export', 'site_requests.calendar.export');

        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/templates', 'site_requests.templates.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/templates/popular', 'site_requests.templates.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/templates/{id}', 'site_requests.templates.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/templates', 'site_requests.templates.manage');
        $this->assertRoutePermission('PUT', 'api/v1/admin/site-requests/templates/{id}', 'site_requests.templates.manage');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/site-requests/templates/{id}', 'site_requests.templates.manage');
        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/templates/{templateId}/create', 'site_requests.create');

        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests', 'site_requests.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/{id}', 'site_requests.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/site-requests/groups/{id}', 'site_requests.view');

        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests', 'site_requests.create');
        $this->assertRoutePermission('PUT', 'api/v1/admin/site-requests/{id}', 'site_requests.edit');
        $this->assertRoutePermission('PUT', 'api/v1/admin/site-requests/groups/{id}', 'site_requests.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/site-requests/{id}', 'site_requests.delete');

        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/{id}/files', 'site_requests.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/site-requests/{id}/files/{fileId}', 'site_requests.edit');
        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/{id}/submit', 'site_requests.edit');
        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/groups/{id}/submit', 'site_requests.edit');

        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/{id}/status', 'site_requests.change_status');
        $this->assertRoutePermission('POST', 'api/v1/admin/site-requests/{id}/assign', 'site_requests.assign');
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "Маршрут {$method} {$uri} не найден.");

        $middleware = $route->gatherMiddleware();
        $allowed = [
            "authorize:{$permission}",
            AuthorizeMiddleware::class . ":{$permission}",
        ];

        foreach ($allowed as $candidate) {
            if (in_array($candidate, $middleware, true)) {
                return;
            }
        }

        $this->fail(
            "Маршрут {$method} {$uri} не содержит middleware для права {$permission}. Фактический стек: "
            . implode(', ', $middleware)
        );
    }

    private function findRoute(string $method, string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
