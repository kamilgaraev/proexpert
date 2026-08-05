<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowOptionsService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\IntercompanyContractFlowReportOptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

final class IntercompanyContractFlowOptionsContractTest extends TestCase
{
    public function test_routes_use_the_dedicated_holding_contract_and_disable_the_generic_run_path(): void
    {
        $routes = file_get_contents(base_path('app/BusinessModules/Core/Reporting/routes.php'));
        $middleware = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/AuthorizeReportDefinitionAccess.php',
        ));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/intercompany-contract-flows/runs'", $routes);
        self::assertStringContainsString("Route::get('/intercompany-contract-flows/options'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'intercompany_contract_flows')", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertIsString($middleware);
        self::assertStringContainsString("'admin.reports.intercompany-contract-flows.runs.store'", $middleware);
        self::assertStringContainsString("'admin.reports.intercompany-contract-flows.options'", $middleware);
        self::assertStringContainsString('IntercompanyContractFlowCandidateContract::CODE', $middleware);
    }

    public function test_options_use_the_runtime_checkpoint_and_only_server_scope_references(): void
    {
        $source = file_get_contents((new ReflectionClass(
            IntercompanyContractFlowOptionsService::class,
        ))->getFileName());
        $controller = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/IntercompanyContractFlowReportOptionsController.php',
        ));

        self::assertIsString($source);
        self::assertStringContainsString('$this->sources->assemble($scope, $query)', $source);
        self::assertStringContainsString('$batch->hierarchy->organizationIds', $source);
        self::assertStringContainsString('$scope->projectIds', $source);
        self::assertStringContainsString('CurrencyCode::options()', $source);
        self::assertStringContainsString("trans_message('contract.work_type_category.'", $source);
        self::assertStringNotContainsString("'organization_id' =>", $source);
        self::assertIsString($controller);
        self::assertStringContainsString(
            '->createRun($request, IntercompanyContractFlowCandidateContract::CODE)',
            $controller,
        );
        self::assertStringContainsString('$context->scope', $controller);
        self::assertStringNotContainsString("input('organization_id')", $controller);
        self::assertStringNotContainsString("input('project_id')", $controller);
    }

    public function test_generic_run_route_cannot_bypass_the_holding_contract(): void
    {
        $request = Request::create(
            '/api/v1/admin/reports/'.IntercompanyContractFlowCandidateContract::CODE.'/runs',
            'POST',
        );
        $request->setUserResolver(static fn (): User => new User);
        $request->attributes->set('current_organization_id', 1);
        $route = new Route(['POST'], '/api/v1/admin/reports/{reportCode}/runs', static fn (): null => null);
        $route->name('admin.reports.runs.store');
        $route->bind($request);
        $route->setParameter('reportCode', IntercompanyContractFlowCandidateContract::CODE);
        $request->setRouteResolver(static fn (): Route => $route);

        $middleware = new AuthorizeReportDefinitionAccess(
            $this->createMock(ReportHttpAuthorizationTargetResolver::class),
            (new ReflectionClass(ReportDefinitionModuleAuthorizer::class))->newInstanceWithoutConstructor(),
        );

        try {
            $middleware->handle($request, static fn (): Response => new Response(status: 204));
            self::fail('The generic run route must reject the holding report.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    public function test_options_reject_client_context_and_require_exact_as_of(): void
    {
        $rules = (new IntercompanyContractFlowReportOptionsRequest)->rules();

        foreach ([
            'organization_id',
            'current_organization_id',
            'holding_organization_ids',
            'organization_ids',
            'project_id',
            'current_project_id',
            'project_ids',
            'user_id',
            'actor_id',
            'scope',
            'permissions',
        ] as $field) {
            self::assertContains('prohibited', $rules[$field]);
        }
        self::assertSame(['required', 'string'], array_slice($rules['as_of'], 0, 2));
        self::assertNotNull(ReportAsOfParser::parse('2026-08-05T14:15:16.123456+03:00'));
        self::assertNull(ReportAsOfParser::parse('2026-08-05'));
    }
}
