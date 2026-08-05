<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityOptionsService;
use PHPUnit\Framework\TestCase;

final class PortfolioLiquidityOptionsContractTest extends TestCase
{
    public function test_options_route_uses_server_owned_scope_and_authorization(): void
    {
        $routes = file_get_contents(base_path('app/BusinessModules/Core/Reporting/routes.php'));
        $middleware = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/AuthorizeReportDefinitionAccess.php',
        ));
        $controller = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/PortfolioLiquidityReportOptionsController.php',
        ));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/portfolio-liquidity/options'", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertIsString($middleware);
        self::assertStringContainsString("'admin.reports.portfolio-liquidity.options'", $middleware);
        self::assertIsString($controller);
        self::assertStringContainsString('->createRun($request, PortfolioLiquidityCandidateContract::CODE)', $controller);
        self::assertStringContainsString('$context->scope', $controller);
        self::assertStringNotContainsString('organization_id', $controller);
        self::assertStringNotContainsString('project_id', $controller);
    }

    public function test_options_are_real_scope_data_and_canonical_scenarios(): void
    {
        $source = file_get_contents((new \ReflectionClass(PortfolioLiquidityOptionsService::class))->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString("->whereIn('id', \$scope->projectIds)", $source);
        self::assertStringContainsString("->where('organization_id', \$scope->organizationId)", $source);
        self::assertStringContainsString("CashGapForecastContext::SCENARIO_BASE, 'name' => 'Базовый'", $source);
        self::assertStringNotContainsString("'organization_id' =>", $source);
    }
}
