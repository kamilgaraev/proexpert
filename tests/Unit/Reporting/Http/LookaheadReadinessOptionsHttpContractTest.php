<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\LookaheadReadinessReportOptionsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\LookaheadReadinessReportOptionsRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LookaheadReadinessOptionsHttpContractTest extends TestCase
{
    public function test_project_scoped_options_route_and_server_owned_context_are_wired(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/routes.php');
        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/projects/{project}/lookahead-readiness/options'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'lookahead_readiness')", $routes);
        self::assertStringContainsString("->middleware(['project.context', 'report.project-scope', \$resourceAccess])", $routes);

        $controller = file_get_contents((new ReflectionClass(LookaheadReadinessReportOptionsController::class))->getFileName());
        self::assertIsString($controller);
        self::assertStringContainsString('LookaheadReadinessCandidateContract::CODE', $controller);
        self::assertStringContainsString('$context->scope', $controller);
        self::assertStringContainsString('$context', $controller);
    }

    public function test_request_accepts_only_period_inputs_and_prohibits_context_override(): void
    {
        $request = file_get_contents((new ReflectionClass(LookaheadReadinessReportOptionsRequest::class))->getFileName());
        self::assertIsString($request);
        self::assertStringContainsString("'as_of'", $request);
        self::assertStringContainsString("'horizon_days'", $request);
        self::assertStringContainsString("'project_id' => ['prohibited']", $request);
        self::assertStringContainsString("'scope' => ['prohibited']", $request);
        self::assertStringContainsString("'actor_id' => ['prohibited']", $request);
    }
}
