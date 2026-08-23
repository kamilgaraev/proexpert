<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Illuminate\Routing\Route;
use Tests\TestCase;

class GeocodingRoutesTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_admin_api_registers_geocoding_routes_before_projects_routes(): void
    {
        $routes = array_values(app('router')->getRoutes()->getRoutes());
        $geocodingRoute = app('router')->getRoutes()->getByName('admin.projects.geocoding.status');
        $projectRoute = app('router')->getRoutes()->getByName('admin.projects.show');

        $this->assertInstanceOf(Route::class, $geocodingRoute);
        $this->assertInstanceOf(Route::class, $projectRoute);
        $this->assertLessThan(
            array_search($projectRoute, $routes, true),
            array_search($geocodingRoute, $routes, true),
        );
    }

    public function test_project_detail_routes_are_limited_to_numeric_identifiers(): void
    {
        foreach (['show', 'update', 'patch', 'destroy'] as $action) {
            $route = app('router')->getRoutes()->getByName('admin.projects.'.$action);

            $this->assertInstanceOf(Route::class, $route);
            $this->assertSame('[0-9]+', $route->wheres['project'] ?? null);
        }
    }
}
