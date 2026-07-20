<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\MultiOrganization;

use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingContractsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingDashboardController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingFilterController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingLegalArchiveController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingProjectsController;
use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingReportsController;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class MultiOrganizationPermissionsTest extends TestCase
{
    public function test_read_routes_require_multi_organization_view_permission(): void
    {
        foreach ([
            'api.v1.landing.multiOrganization.hierarchy',
            'api.v1.landing.multiOrganization.accessible',
            'api.v1.landing.multiOrganization.organizationData',
            'api.v1.landing.multiOrganization.getChildOrganizations',
            'api.v1.landing.multiOrganization.getRoleTemplates',
            'api.v1.landing.multiOrganization.legalArchive.contracts.show',
            'api.v1.landing.multiOrganization.legalArchive.versions.preview',
            'api.v1.landing.multiOrganization.legalArchive.versions.download',
        ] as $routeName) {
            $this->assertRouteHasMiddleware($routeName, 'authorize:multi-organization.view');
        }
    }

    public function test_dashboard_routes_require_dashboard_permission(): void
    {
        foreach ([
            'api.v1.landing.multiOrganization.dashboard',
            'api.v1.landing.multiOrganization.dashboardV2',
        ] as $routeName) {
            $this->assertRouteHasMiddleware($routeName, 'authorize:multi-organization.dashboard');
        }
    }

    public function test_report_routes_require_granular_report_permissions(): void
    {
        $expected = [
            'api.v1.landing.multiOrganization.reports.projects-summary' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.contracts-summary' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.intra-group' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.consolidated' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.detailed-contracts' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.contracts' => 'authorize:multi-organization.reports.financial',
            'api.v1.landing.multiOrganization.reports.contractsSummary' => 'authorize:multi-organization.reports.financial',
            'api.v1.landing.multiOrganization.reports.acts' => 'authorize:multi-organization.reports.view',
            'api.v1.landing.multiOrganization.reports.movements' => 'authorize:multi-organization.reports.view',
        ];

        foreach ($expected as $routeName => $middleware) {
            $this->assertRouteHasMiddleware($routeName, $middleware);
        }
    }

    public function test_management_routes_keep_manage_permission(): void
    {
        foreach ([
            'api.v1.landing.multiOrganization.createHolding',
            'api.v1.landing.multiOrganization.addChild',
            'api.v1.landing.multiOrganization.updateChildOrganization',
            'api.v1.landing.multiOrganization.deleteChildOrganization',
            'api.v1.landing.multiOrganization.updateHoldingSettings',
        ] as $routeName) {
            $this->assertRouteHasMiddleware($routeName, 'authorize:multi-organization.manage');
        }
    }

    public function test_lk_multi_organization_core_controllers_do_not_use_admin_response(): void
    {
        foreach ([
            HoldingDashboardController::class,
            HoldingFilterController::class,
            HoldingProjectsController::class,
            HoldingReportsController::class,
            HoldingContractsController::class,
            HoldingLegalArchiveController::class,
        ] as $controllerClass) {
            $source = file_get_contents((new ReflectionClass($controllerClass))->getFileName());

            $this->assertIsString($source);
            $this->assertStringNotContainsString('AdminResponse', $source, $controllerClass);
            $this->assertStringContainsString('LandingResponse', $source, $controllerClass);
        }
    }

    public function test_check_availability_is_the_only_multi_organization_route_without_module_access(): void
    {
        $routesWithoutModuleAccess = array_map(
            static fn (IlluminateRoute $route): string => (string) $route->getName(),
            array_filter(
                $this->coreMultiOrganizationRoutes(),
                static fn (IlluminateRoute $route): bool => in_array(
                    'module.access:multi-organization',
                    $route->excludedMiddleware(),
                    true
                )
            )
        );

        sort($routesWithoutModuleAccess);

        $this->assertSame([
            'api.v1.landing.multiOrganization.checkAvailability',
        ], $routesWithoutModuleAccess);
    }

    private function assertRouteHasMiddleware(string $routeName, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$routeName}] misses [{$middleware}].");
    }

    /**
     * @return array<int, IlluminateRoute>
     */
    private function coreMultiOrganizationRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/landing/multi-organization')
        ));
    }
}
