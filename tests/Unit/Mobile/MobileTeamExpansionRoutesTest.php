<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileTeamExpansionRoutesTest extends TestCase
{
    public function test_team_expansion_routes_require_mobile_auth_and_domain_permissions(): void
    {
        $cases = [
            'api.v1.mobile.team_expansion.contractors.index' => 'contractor_marketplace.search.view',
            'api.v1.mobile.team_expansion.contractors.show' => 'contractor_marketplace.profile.view',
            'api.v1.mobile.team_expansion.contractors.invite' => 'contractor_marketplace.offers.create',
            'api.v1.mobile.team_expansion.brigades.index' => 'brigades.catalog.view',
            'api.v1.mobile.team_expansion.brigades.show' => 'brigades.catalog.view',
            'api.v1.mobile.team_expansion.brigade_requests.index' => 'brigades.requests.view',
            'api.v1.mobile.team_expansion.brigade_requests.store' => 'brigades.requests.create',
            'api.v1.mobile.team_expansion.brigade_requests.responses.index' => 'brigades.responses.view',
            'api.v1.mobile.team_expansion.brigade_requests.responses.approve' => 'brigades.responses.approve',
            'api.v1.mobile.team_expansion.brigade_invitations.index' => 'brigades.invitations.view',
            'api.v1.mobile.team_expansion.brigade_invitations.store' => 'brigades.invitations.create',
        ];

        foreach ($cases as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:api_mobile', $middleware, $name);
            $this->assertContains('auth.jwt:api_mobile', $middleware, $name);
            $this->assertContains('organization.context', $middleware, $name);
            $this->assertContains('can:access-mobile-app', $middleware, $name);
            $this->assertContains('authorize:'.$permission, $middleware, $name);
            $this->assertSame([], array_values(array_filter($middleware, static fn (string $item): bool => str_starts_with($item, 'role:'))), $name);
        }
    }
}
