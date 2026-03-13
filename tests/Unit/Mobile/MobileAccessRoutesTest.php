<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MobileAccessRoutesTest extends TestCase
{
    public function test_projects_route_has_required_mobile_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.mobile.projects.index');

        $this->assertNotNull($route);
        $this->assertContains('auth:api_mobile', $route->gatherMiddleware());
        $this->assertContains('auth.jwt:api_mobile', $route->gatherMiddleware());
        $this->assertContains('organization.context', $route->gatherMiddleware());
        $this->assertContains('can:access-mobile-app', $route->gatherMiddleware());
    }

    public function test_site_requests_meta_route_has_required_mobile_middleware(): void
    {
        $route = Route::getRoutes()->getByName('mobile.site_requests.meta');

        $this->assertNotNull($route);
        $this->assertContains('auth:api_mobile', $route->gatherMiddleware());
        $this->assertContains('auth.jwt:api_mobile', $route->gatherMiddleware());
        $this->assertContains('organization.context', $route->gatherMiddleware());
        $this->assertContains('can:access-mobile-app', $route->gatherMiddleware());
    }

    public function test_foreman_role_definition_allows_mobile_interface_access(): void
    {
        $definition = json_decode(
            file_get_contents(config_path('RoleDefinitions/mobile/foreman.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('mobile', $definition['interface']);
        $this->assertContains('mobile', $definition['interface_access']);
    }
}
