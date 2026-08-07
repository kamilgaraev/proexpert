<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimCandidateContract;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimReadinessSnapshot;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimOptionsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangeClaimOptionsServiceTest extends TestCase
{
    public function test_candidate_contract_matches_existing_runtime(): void
    {
        $contract = new ChangeClaimCandidateContract;
        $definition = $contract->definition();

        $contract->assertDefinition($definition);
        self::assertSame('change_claim_contingency', $definition->code);
        self::assertSame(['change-management.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['change-management.reports.export'], $definition->permissionPolicy->exportPermissions);
    }

    #[DataProvider('availabilityCases')]
    public function test_availability_is_fail_closed(ChangeClaimReadinessSnapshot $snapshot, string $status): void
    {
        self::assertSame($status, ChangeClaimOptionsService::availability($snapshot)['status']);
        self::assertSame($status === 'available', ChangeClaimOptionsService::availability($snapshot)['can_run']);
    }

    public static function availabilityCases(): array
    {
        return [
            'no facts' => [new ChangeClaimReadinessSnapshot(0, false, false), 'no_data'],
            'checkpoint missing' => [new ChangeClaimReadinessSnapshot(3, false, false), 'source_incomplete'],
            'history gaps' => [new ChangeClaimReadinessSnapshot(3, true, false), 'source_incomplete'],
            'ready' => [new ChangeClaimReadinessSnapshot(3, true, true), 'available'],
        ];
    }

    public function test_options_route_is_organization_scoped_and_rejects_client_context(): void
    {
        $root = dirname(__DIR__, 4);
        $routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
        $request = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Http/Admin/Requests/ChangeClaimReportOptionsRequest.php');

        self::assertStringContainsString("Route::get('/change-claim-contingency/options'", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        foreach (['organization_id', 'current_organization_id', 'holding_organization_ids', 'project_id', 'project_ids', 'scope', 'actor_id'] as $field) {
            self::assertStringContainsString("'{$field}' => ['prohibited']", $request);
        }
    }

    public function test_readiness_requires_checkpoint_and_complete_monetary_history(): void
    {
        $probe = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Readiness/ChangeClaimReadinessProbe.php',
        );

        self::assertStringContainsString('unprojectable_legacy_count', $probe);
        self::assertStringContainsString('$monetaryComplete', $probe);
        self::assertStringContainsString('$approvalComplete', $probe);
        self::assertStringContainsString('$ledgerComplete', $probe);
        self::assertStringContainsString("'availability' => \$snapshot->factCount === 0 ? 'no_data' : 'source_incomplete'", $probe);
    }
}
