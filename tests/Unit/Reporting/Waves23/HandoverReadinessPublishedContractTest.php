<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessCandidateContract;
use PHPUnit\Framework\TestCase;

final class HandoverReadinessPublishedContractTest extends TestCase
{
    public function test_contract_has_no_client_owned_context_filters(): void
    {
        $contract = new HandoverReadinessCandidateContract;
        $definition = (new HandoverReadinessBuiltinPublishedReport($contract))->definition()->payload();

        self::assertSame(['as_of'], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_id', array_column($definition->filters, 'id'));
        self::assertSame(ReportCoreAccessMode::REPORTING_WORKSPACE, $definition->coreAccessMode);
        self::assertSame(['reports.project_readiness.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['reports.project_readiness.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertContains('ready', array_column($definition->columns, 'id'));
        self::assertContains('open_hard_blocker_count', array_column($definition->columns, 'id'));
    }

    public function test_runtime_fingerprints_match_owner_code(): void
    {
        $contract = new HandoverReadinessCandidateContract;
        $contract->assertRuntimeMatches();

        self::assertSame(64, strlen(HandoverReadinessCandidateContract::FORMULA_HASH));
        self::assertSame(64, strlen(HandoverReadinessCandidateContract::SOURCE_HASH));
    }
}
