<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportWorkspacePreferencesController;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\RecordRecentReportRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\SetReportWorkspaceFavouritesRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportWorkspacePreferencesRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportWorkspacePreferencesResource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReportWorkspaceRouteContractTest extends TestCase
{
    public function test_routes_expose_exact_workspace_endpoints_and_permissions(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/routes.php');

        foreach ([
            "Route::get('/workspace'",
            "Route::post('/workspace/recent/{reportCode}'",
            "Route::put('/workspace/favourites'",
            "Route::patch('/workspace/preferences'",
            "'authorize:reports.manage'",
        ] as $fragment) {
            self::assertStringContainsString($fragment, $routes);
        }
    }

    public function test_mutation_requests_prohibit_foreign_owner_input(): void
    {
        foreach ([RecordRecentReportRequest::class, SetReportWorkspaceFavouritesRequest::class, UpdateReportWorkspacePreferencesRequest::class] as $request) {
            self::assertContains('prohibited', (new $request)->rules()['owner_id']);
        }
    }

    public function test_controller_has_one_request_per_endpoint_and_standard_response(): void
    {
        $controller = new \ReflectionClass(ReportWorkspacePreferencesController::class);
        $methods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $controller->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $controller->getName()
                    && $method->getName() !== '__construct',
            ),
        ));
        sort($methods);
        self::assertSame(['recordRecent', 'setFavourites', 'show', 'updatePreferences'], $methods);
        self::assertSame(['recent_report_codes', 'favourite_report_codes', 'display_preferences', 'updated_at'], array_keys(
            (new ReportWorkspacePreferencesResource(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences::defaults()))->toArray(new \Illuminate\Http\Request),
        ));
    }
}
