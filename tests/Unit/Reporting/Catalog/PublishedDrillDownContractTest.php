<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown\InventoryRiskDrillDownProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportAdapter;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DrillDown\SupplyReliabilityDrillDownProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityCandidateContract;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowCandidateContract;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionCandidateContract;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceQueryService;
use App\Services\Customer\Reporting\Sla\CustomerSlaCandidateContract;
use App\Services\Customer\Reporting\Sla\DrillDown\CustomerSlaDrillDownProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishedDrillDownContractTest extends TestCase
{
    #[DataProvider('simpleReports')]
    public function test_published_drill_down_has_signed_token_column(string $provider, object $contract): void
    {
        self::assertTrue(is_subclass_of($provider, ReportDrillDownTokenColumns::class));
        self::assertContains('drill', array_column($contract->columns(), 'id'));
    }

    public function test_procurement_cycle_declares_both_signed_token_columns(): void
    {
        self::assertTrue(is_subclass_of(ProcurementCycleReportAdapter::class, ReportDrillDownTokenColumns::class));
        $columns = array_column((new ProcurementCycleCandidateContract)->columns(), 'id');
        self::assertContains('stage_breakdown', $columns);
        self::assertContains('audit_timeline', $columns);
    }

    public static function simpleReports(): iterable
    {
        yield [BaselineScheduleVarianceQueryService::class, new BaselineScheduleVarianceCandidateContract];
        yield [CustomerSlaDrillDownProvider::class, new CustomerSlaCandidateContract];
        yield [SupplierAwardRowQuery::class, new SupplierAwardCandidateContract];
        yield [SupplyReliabilityDrillDownProvider::class, new SupplyReliabilityCandidateContract];
        yield [InventoryRiskDrillDownProvider::class, new InventoryRiskCandidateContract];
        yield [QualityDefectFlowDrillDownProvider::class, new QualityDefectFlowCandidateContract];
        yield [SafetyIncidentDrillDownProvider::class, new SafetyIncidentActionsCandidateContract];
        yield [WorkforceAdmissionDrillDownProvider::class, new WorkforceAdmissionCandidateContract];
    }
}
