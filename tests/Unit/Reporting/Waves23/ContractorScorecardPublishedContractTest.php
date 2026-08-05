<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardBuiltinPublishedReport;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardCandidateContract;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use PHPUnit\Framework\TestCase;

final class ContractorScorecardPublishedContractTest extends TestCase
{
    public function test_contract_keeps_scope_server_owned_and_exposes_evidence(): void
    {
        $contract = new ContractorScorecardCandidateContract;
        $definition = (new ContractorScorecardBuiltinPublishedReport($contract))->definition()->payload();

        self::assertSame(['as_of', 'cohort'], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_id', array_column($definition->filters, 'id'));
        self::assertSame(ReportCoreAccessMode::SOURCE_MODULE_REPORT, $definition->coreAccessMode);
        self::assertSame(['contractor_marketplace.profile.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['contractor_marketplace.reports.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertContains('component_mean', array_column($definition->columns, 'id'));
        self::assertContains('coverage', array_column($definition->columns, 'id'));
        self::assertContains('drill', array_column($definition->columns, 'id'));
    }

    public function test_runtime_fingerprints_match_owner_code(): void
    {
        $contract = new ContractorScorecardCandidateContract;
        $contract->assertRuntimeMatches();

        self::assertSame(64, strlen(ContractorScorecardCandidateContract::FORMULA_HASH));
        self::assertSame(64, strlen(ContractorScorecardCandidateContract::SOURCE_HASH));
    }
}
