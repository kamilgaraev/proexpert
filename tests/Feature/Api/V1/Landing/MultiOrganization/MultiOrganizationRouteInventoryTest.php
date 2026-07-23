<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\MultiOrganization;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MultiOrganizationRouteInventoryTest extends TestCase
{
    public function test_core_multi_organization_routes_match_expected_inventory(): void
    {
        $this->assertSame(
            $this->expectedCoreMultiOrganizationRoutes(),
            $this->routesStartingWith('api/v1/landing/multi-organization')
        );
    }

    public function test_holding_public_and_site_contours_are_not_core_multi_organization_routes(): void
    {
        $coreRoutes = $this->routesStartingWith('api/v1/landing/multi-organization');
        $holdingApiRoutes = $this->routesStartingWith('api/v1/holding-api');
        $holdingSiteRoutes = $this->routesStartingWith('api/v1/landing/holding/site');
        $holdingPublicRoutes = $this->routesStartingWith('api/v1/landing/holding/public');

        $this->assertNotEmpty($holdingApiRoutes);
        $this->assertNotEmpty($holdingSiteRoutes);
        $this->assertNotEmpty($holdingPublicRoutes);

        $coreUris = array_column($coreRoutes, 'uri');

        foreach (array_merge($holdingApiRoutes, $holdingSiteRoutes, $holdingPublicRoutes) as $route) {
            $this->assertNotContains($route['uri'], $coreUris);
        }
    }

    public function test_public_holding_api_does_not_expose_child_organization_mutations(): void
    {
        $holdingApiUris = array_column($this->routesStartingWith('api/v1/holding-api'), 'uri');

        $this->assertNotContains('api/v1/holding-api/{slug}/add-child', $holdingApiUris);
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string}>
     */
    private function expectedCoreMultiOrganizationRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/accessible',
                'name' => 'api.v1.landing.multiOrganization.accessible',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/landing/multi-organization/add-child',
                'name' => 'api.v1.landing.multiOrganization.addChild',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/check-availability',
                'name' => 'api.v1.landing.multiOrganization.checkAvailability',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/child-organizations',
                'name' => 'api.v1.landing.multiOrganization.getChildOrganizations',
            ],
            [
                'method' => 'DELETE',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}',
                'name' => 'api.v1.landing.multiOrganization.deleteChildOrganization',
            ],
            [
                'method' => 'PUT',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}',
                'name' => 'api.v1.landing.multiOrganization.updateChildOrganization',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/roles',
                'name' => 'api.v1.landing.multiOrganization.getChildOrganizationRoles',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/stats',
                'name' => 'api.v1.landing.multiOrganization.getChildOrganizationStats',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/users',
                'name' => 'api.v1.landing.multiOrganization.getChildOrganizationUsers',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/users',
                'name' => 'api.v1.landing.multiOrganization.addUserToChildOrganization',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/bulk',
                'name' => 'api.v1.landing.multiOrganization.createBulkUsers',
            ],
            [
                'method' => 'DELETE',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/{userId}',
                'name' => 'api.v1.landing.multiOrganization.removeUserFromChildOrganization',
            ],
            [
                'method' => 'PUT',
                'uri' => 'api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/{userId}',
                'name' => 'api.v1.landing.multiOrganization.updateUserInChildOrganization',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/contracts-v2',
                'name' => 'api.v1.landing.multiOrganization.contractsV2.index',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/contracts/{contractId}',
                'name' => 'api.v1.landing.multiOrganization.contracts.show',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/legal-archive/contracts/{contractId}',
                'name' => 'api.v1.landing.multiOrganization.legalArchive.contracts.show',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/legal-archive/contracts/{contractId}/versions/{versionId}/download',
                'name' => 'api.v1.landing.multiOrganization.legalArchive.versions.download',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/legal-archive/contracts/{contractId}/versions/{versionId}/preview',
                'name' => 'api.v1.landing.multiOrganization.legalArchive.versions.preview',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/landing/multi-organization/create-holding',
                'name' => 'api.v1.landing.multiOrganization.createHolding',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/dashboard',
                'name' => 'api.v1.landing.multiOrganization.dashboard',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/dashboard-v2',
                'name' => 'api.v1.landing.multiOrganization.dashboardV2',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/filter-options',
                'name' => 'api.v1.landing.multiOrganization.filterOptions',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/hierarchy',
                'name' => 'api.v1.landing.multiOrganization.hierarchy',
            ],
            [
                'method' => 'PUT',
                'uri' => 'api/v1/landing/multi-organization/holding-settings',
                'name' => 'api.v1.landing.multiOrganization.updateHoldingSettings',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/organization/{organizationId}',
                'name' => 'api.v1.landing.multiOrganization.organizationData',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/projects',
                'name' => 'api.v1.landing.multiOrganization.projects.index',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/projects/{projectId}',
                'name' => 'api.v1.landing.multiOrganization.projects.show',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/acts',
                'name' => 'api.v1.landing.multiOrganization.reports.acts',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/consolidated',
                'name' => 'api.v1.landing.multiOrganization.reports.consolidated',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/contracts',
                'name' => 'api.v1.landing.multiOrganization.reports.contracts',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/contracts-summary',
                'name' => 'api.v1.landing.multiOrganization.reports.contracts-summary',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/contracts/summary',
                'name' => 'api.v1.landing.multiOrganization.reports.contractsSummary',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/detailed-contracts',
                'name' => 'api.v1.landing.multiOrganization.reports.detailed-contracts',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/intra-group',
                'name' => 'api.v1.landing.multiOrganization.reports.intra-group',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/movements',
                'name' => 'api.v1.landing.multiOrganization.reports.movements',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/reports/projects-summary',
                'name' => 'api.v1.landing.multiOrganization.reports.projects-summary',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/role-templates',
                'name' => 'api.v1.landing.multiOrganization.getRoleTemplates',
            ],
            [
                'method' => 'GET',
                'uri' => 'api/v1/landing/multi-organization/summary',
                'name' => 'api.v1.landing.multiOrganization.summary',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/v1/landing/multi-organization/switch-context',
                'name' => 'api.v1.landing.multiOrganization.switchContext',
            ],
        ];
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string}>
     */
    private function routesStartingWith(string $prefix): array
    {
        $routes = array_filter(
            array_map(
                static fn (IlluminateRoute $route): array => [
                    'method' => self::normalizeMethods($route),
                    'uri' => $route->uri(),
                    'name' => (string) $route->getName(),
                ],
                Route::getRoutes()->getRoutes()
            ),
            static fn (array $route): bool => str_starts_with($route['uri'], $prefix)
        );

        usort(
            $routes,
            static fn (array $left, array $right): int => [$left['uri'], $left['method'], $left['name']]
                <=> [$right['uri'], $right['method'], $right['name']]
        );

        return array_values($routes);
    }

    private static function normalizeMethods(IlluminateRoute $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        sort($methods);

        return implode('|', $methods);
    }
}
