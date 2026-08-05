<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\Services\Customer\Reporting\Sla\CustomerSlaBuiltinPublishedReport;
use App\Services\Customer\Reporting\Sla\CustomerSlaCandidateContract;
use PHPUnit\Framework\TestCase;

final class CustomerSlaPublishedContractTest extends TestCase
{
    public function test_contract_exposes_only_business_filters(): void
    {
        $contract = new CustomerSlaCandidateContract;
        $definition = (new CustomerSlaBuiltinPublishedReport($contract))->definition()->payload();

        self::assertSame(['period_from', 'period_to', 'workflow_types'], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_id', array_column($definition->filters, 'id'));
        self::assertNotContains('customer_organization_id', array_column($definition->filters, 'id'));
        self::assertSame(ReportCoreAccessMode::REPORTING_WORKSPACE, $definition->coreAccessMode);
        self::assertSame(['customer.sla_report.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['customer.sla_report.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
    }

    public function test_runtime_fingerprints_match_owner_code(): void
    {
        $contract = new CustomerSlaCandidateContract;

        $contract->assertRuntimeMatches();
        self::assertSame(64, strlen(CustomerSlaCandidateContract::FORMULA_HASH));
        self::assertSame(64, strlen(CustomerSlaCandidateContract::SOURCE_HASH));
    }
}
