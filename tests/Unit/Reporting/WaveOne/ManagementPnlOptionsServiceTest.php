<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlReadinessSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Services\ManagementPnlOptionsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagementPnlOptionsServiceTest extends TestCase
{
    public function test_candidate_contract_matches_existing_runtime(): void
    {
        $contract = new ManagementPnlCandidateContract;
        $definition = $contract->definition();

        $contract->assertDefinition($definition);
        self::assertSame('management_pnl', $definition->code);
        self::assertSame(['budgeting.management_pnl.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['budgeting.management_pnl.export'], $definition->permissionPolicy->exportPermissions);
    }

    #[DataProvider('readinessCases')]
    public function test_availability_is_fail_closed(ManagementPnlReadinessSnapshot $snapshot, string $status): void
    {
        self::assertSame($status, ManagementPnlOptionsService::availability($snapshot)['status']);
        self::assertSame($status === 'available', ManagementPnlOptionsService::availability($snapshot)['can_run']);
    }

    public static function readinessCases(): array
    {
        return [
            'no facts' => [new ManagementPnlReadinessSnapshot(0, false, false, [], []), 'no_data'],
            'policy missing' => [new ManagementPnlReadinessSnapshot(3, false, true, ['RUB'], ['actual']), 'source_incomplete'],
            'sealed tuple missing' => [new ManagementPnlReadinessSnapshot(3, true, false, ['RUB'], ['actual']), 'source_incomplete'],
            'ready' => [new ManagementPnlReadinessSnapshot(3, true, true, ['RUB'], ['actual']), 'available'],
        ];
    }

    public function test_options_http_contract_is_organization_scoped_and_rejects_client_context(): void
    {
        $root = dirname(__DIR__, 4);
        $routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
        $request = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Http/Admin/Requests/ManagementPnlReportOptionsRequest.php');
        $controller = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ManagementPnlReportOptionsController.php');

        self::assertStringContainsString("Route::get('/management-pnl/options'", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        foreach (['organization_id', 'current_organization_id', 'holding_organization_ids', 'project_id', 'project_ids', 'scope', 'actor_id'] as $field) {
            self::assertStringContainsString("'{$field}' => ['prohibited']", $request);
        }
        self::assertStringContainsString('AdminResponse::success', $controller);
    }
}
