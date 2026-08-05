<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

final class ProjectMarginCanonicalRouteContractTest extends TestCase
{
    public function test_project_margin_uses_only_project_context_routes_and_authorization_targets(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/routes.php');
        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/projects/{project}/project-margin/runs'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'project_margin')", $routes);
        self::assertStringContainsString("->middleware(['project.context', 'report.project-scope', \$resourceAccess])", $routes);
        self::assertStringContainsString("Route::get('/projects/{project}/project-margin/options'", $routes);
        self::assertStringContainsString("Route::post('/projects/{project}/project-evm-control/runs'", $routes);
        self::assertStringContainsString("Route::post('/projects/{project}/wip-completion-forecast/runs'", $routes);
        self::assertStringContainsString("Route::get('/projects/{project}/wip-completion-forecast/options'", $routes);
        self::assertStringContainsString("Route::post('/projects/{project}/project-labor-cost/runs'", $routes);
        self::assertStringContainsString("Route::get('/projects/{project}/project-labor-cost/options'", $routes);
        self::assertStringContainsString("Route::post('/projects/{project}/payroll-readiness/runs'", $routes);
        self::assertStringContainsString("Route::get('/projects/{project}/payroll-readiness/options'", $routes);
        self::assertStringContainsString("Route::post('/workforce-capacity/runs'", $routes);
        self::assertStringContainsString("Route::get('/workforce-capacity/options'", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertSame(11, substr_count($routes, "'report.project-scope'"));

        $middlewareFile = (new ReflectionClass(AuthorizeReportDefinitionAccess::class))->getFileName();
        self::assertIsString($middlewareFile);
        $middleware = file_get_contents($middlewareFile);
        self::assertIsString($middleware);
        self::assertStringContainsString("'admin.reports.project-margin.runs.store'", $middleware);
        self::assertStringContainsString("'admin.reports.project-margin.options'", $middleware);
        self::assertStringContainsString("'admin.reports.project-evm-control.runs.store'", $middleware);
        self::assertStringContainsString("'admin.reports.wip-completion-forecast.runs.store'", $middleware);
        self::assertStringContainsString("'admin.reports.wip-completion-forecast.options'", $middleware);
        self::assertStringContainsString('private function genericCreateRun', $middleware);
        self::assertStringContainsString('ProjectMarginCandidateContract::CODE', $middleware);
        self::assertStringContainsString('BudgetPlanFactCandidateContract::CODE', $middleware);
        self::assertStringContainsString('PayrollReadinessCandidateContract::CODE', $middleware);
        self::assertStringContainsString('WorkforceCapacityCandidateContract::CODE', $middleware);
    }

    #[DataProvider('projectScopedReportCodes')]
    public function test_generic_run_route_rejects_project_scoped_reports(string $reportCode): void
    {
        $request = Request::create('/api/v1/admin/reports/'.$reportCode.'/runs', 'POST');
        $request->setUserResolver(static fn (): User => new User);
        $request->attributes->set('current_organization_id', 1);
        $route = new Route(['POST'], '/api/v1/admin/reports/{reportCode}/runs', static fn (): null => null);
        $route->name('admin.reports.runs.store');
        $route->bind($request);
        $route->setParameter('reportCode', $reportCode);
        $request->setRouteResolver(static fn (): Route => $route);

        $middleware = new AuthorizeReportDefinitionAccess(
            $this->createMock(ReportHttpAuthorizationTargetResolver::class),
            (new ReflectionClass(ReportDefinitionModuleAuthorizer::class))->newInstanceWithoutConstructor(),
        );

        try {
            $middleware->handle($request, static fn (): Response => new Response(status: 204));
            self::fail('Project-scoped report must reject the generic run route.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    public static function projectScopedReportCodes(): array
    {
        return [
            'G09' => [ProjectMarginCandidateContract::CODE],
            'G10' => [BudgetPlanFactCandidateContract::CODE],
            'G05' => [ProjectEvmControlCandidateContract::CODE],
            'G11' => [WipCompletionForecastCandidateContract::CODE],
            'G21' => [ProjectLaborCostCandidateContract::CODE],
            'G22' => [PayrollReadinessCandidateContract::CODE],
            'G19' => [WorkforceCapacityCandidateContract::CODE],
        ];
    }
}
