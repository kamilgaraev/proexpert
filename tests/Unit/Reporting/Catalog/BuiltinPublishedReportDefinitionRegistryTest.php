<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinPublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionCandidateContract;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowBuiltinPublishedReport;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowCandidateContract;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionCandidateContract;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BuiltinPublishedReportDefinitionRegistryTest extends TestCase
{
    public function test_provider_exposes_builtin_through_generic_registry(): void
    {
        $app = new Application(dirname(__DIR__, 4));
        $app->instance(DatabasePublishedReportDefinitionRegistry::class, $this->registry([]));
        $app->instance(DatabaseReportCatalogMetadataRegistry::class, new class implements ReportCatalogMetadataRegistry
        {
            public function published(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
        });
        $app->instance(DatabaseReportSchedulingCapabilityRegistry::class, new class implements ReportSchedulingCapabilityRegistry
        {
            public function published(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
        });
        (new ReportingCatalogServiceProvider($app))->register();

        $registry = $app->make(ReportDefinitionRegistry::class);

        self::assertSame('project_margin', $registry->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $registry->published('budget_plan_fact')->code);
        self::assertSame('project_labor_cost', $registry->published('project_labor_cost')->code);
        self::assertSame('payroll_readiness', $registry->published('payroll_readiness')->code);
        self::assertSame('workforce_capacity', $registry->published('workforce_capacity')->code);
        self::assertSame('procurement_cycle', $registry->published('procurement_cycle')->code);
        self::assertSame('supplier_award_competitiveness', $registry->published('supplier_award_competitiveness')->code);
        self::assertSame('supply_reliability', $registry->published('supply_reliability')->code);
        self::assertSame('inventory_risk', $registry->published('inventory_risk')->code);
        self::assertSame('attendance_execution', $registry->published('attendance_execution')->code);
        self::assertSame('quality_defect_flow', $registry->published('quality_defect_flow')->code);
        self::assertSame('workforce_admission', $registry->published('workforce_admission')->code);
        self::assertSame('project_margin', $app->make(ReportCatalogMetadataRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportCatalogMetadataRegistry::class)->published('budget_plan_fact')->code);
        self::assertSame('project_labor_cost', $app->make(ReportCatalogMetadataRegistry::class)->published('project_labor_cost')->code);
        self::assertSame('payroll_readiness', $app->make(ReportCatalogMetadataRegistry::class)->published('payroll_readiness')->code);
        self::assertSame('workforce_capacity', $app->make(ReportCatalogMetadataRegistry::class)->published('workforce_capacity')->code);
        self::assertSame('procurement_cycle', $app->make(ReportCatalogMetadataRegistry::class)->published('procurement_cycle')->code);
        self::assertSame('supplier_award_competitiveness', $app->make(ReportCatalogMetadataRegistry::class)->published('supplier_award_competitiveness')->code);
        self::assertSame('supply_reliability', $app->make(ReportCatalogMetadataRegistry::class)->published('supply_reliability')->code);
        self::assertSame('inventory_risk', $app->make(ReportCatalogMetadataRegistry::class)->published('inventory_risk')->code);
        self::assertSame('attendance_execution', $app->make(ReportCatalogMetadataRegistry::class)->published('attendance_execution')->code);
        self::assertSame('quality_defect_flow', $app->make(ReportCatalogMetadataRegistry::class)->published('quality_defect_flow')->code);
        self::assertSame('workforce_admission', $app->make(ReportCatalogMetadataRegistry::class)->published('workforce_admission')->code);
        self::assertSame('project_margin', $app->make(ReportSchedulingCapabilityRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportSchedulingCapabilityRegistry::class)->published('budget_plan_fact')->code);
        self::assertSame('project_labor_cost', $app->make(ReportSchedulingCapabilityRegistry::class)->published('project_labor_cost')->code);
        self::assertSame('payroll_readiness', $app->make(ReportSchedulingCapabilityRegistry::class)->published('payroll_readiness')->code);
        self::assertSame('workforce_capacity', $app->make(ReportSchedulingCapabilityRegistry::class)->published('workforce_capacity')->code);
        self::assertSame('procurement_cycle', $app->make(ReportSchedulingCapabilityRegistry::class)->published('procurement_cycle')->code);
        self::assertSame('supplier_award_competitiveness', $app->make(ReportSchedulingCapabilityRegistry::class)->published('supplier_award_competitiveness')->code);
        self::assertSame('supply_reliability', $app->make(ReportSchedulingCapabilityRegistry::class)->published('supply_reliability')->code);
        self::assertSame('inventory_risk', $app->make(ReportSchedulingCapabilityRegistry::class)->published('inventory_risk')->code);
        self::assertSame('attendance_execution', $app->make(ReportSchedulingCapabilityRegistry::class)->published('attendance_execution')->code);
        self::assertSame('quality_defect_flow', $app->make(ReportSchedulingCapabilityRegistry::class)->published('quality_defect_flow')->code);
        self::assertSame('workforce_admission', $app->make(ReportSchedulingCapabilityRegistry::class)->published('workforce_admission')->code);
    }

    public function test_budget_plan_fact_is_available_without_database_publication(): void
    {
        $builtins = new BuiltinPublishedReportDefinitionRegistry(
            $this->projectMargin(),
            new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract),
            $this->projectLaborCost(),
            $this->payrollReadiness(),
            $this->workforceCapacity(),
            $this->procurementCycle(),
            $this->supplierAward(),
            $this->supplyReliability(),
            $this->inventoryRisk(),
            $this->attendanceExecution(),
            $this->qualityDefectFlow(),
            $this->workforceAdmission(),
        );
        $registry = new CompositePublishedReportDefinitionRegistry($builtins, $this->registry([]));

        self::assertSame(['attendance_execution', 'budget_plan_fact', 'inventory_risk', 'payroll_readiness', 'procurement_cycle', 'project_labor_cost', 'project_margin', 'quality_defect_flow', 'supplier_award_competitiveness', 'supply_reliability', 'workforce_admission', 'workforce_capacity'], $registry->publishedCodes());
        self::assertSame('project_margin', $registry->published('project_margin')->code);
        $definition = $registry->published('budget_plan_fact');
        $payload = $definition->payload();

        self::assertSame('budget_plan_fact', $definition->code);
        self::assertSame(['budgeting.plan_fact.view'], $payload->permissionPolicy->viewPermissions);
        self::assertSame(['budgeting.plan_fact.export'], $payload->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $payload->formats);
        self::assertSame(
            hash('sha256', CanonicalJson::encode((new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->document())),
            $definition->definitionHash->value,
        );
    }

    public function test_builtin_reports_use_owner_module_entitlements(): void
    {
        $registry = new BuiltinPublishedReportDefinitionRegistry(
            $this->projectMargin(),
            new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract),
            $this->projectLaborCost(),
            $this->payrollReadiness(),
            $this->workforceCapacity(),
            $this->procurementCycle(),
            $this->supplierAward(),
            $this->supplyReliability(),
            $this->inventoryRisk(),
            $this->attendanceExecution(),
            $this->qualityDefectFlow(),
            $this->workforceAdmission(),
        );

        $expectedModules = [
            'budget_plan_fact' => 'budgeting',
            'project_margin' => 'budgeting',
            'project_labor_cost' => 'time-tracking',
            'payroll_readiness' => 'workforce-management',
            'workforce_capacity' => 'workforce-management',
            'procurement_cycle' => 'procurement',
            'supplier_award_competitiveness' => 'procurement',
            'supply_reliability' => 'procurement',
            'inventory_risk' => 'basic-warehouse',
            'attendance_execution' => 'workforce-management',
            'quality_defect_flow' => 'quality-control',
            'workforce_admission' => 'safety-management',
        ];

        foreach ($expectedModules as $code => $module) {
            $definition = $registry->published($code)->payload();

            self::assertSame($module, $definition->sourceModule);
            self::assertSame(ReportCoreAccessMode::SOURCE_MODULE_REPORT, $definition->coreAccessMode);
        }
    }

    public function test_database_published_report_remains_available(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract), $this->projectLaborCost(), $this->payrollReadiness(), $this->workforceCapacity(), $this->procurementCycle(), $this->supplierAward(), $this->supplyReliability(), $this->inventoryRisk(), $this->attendanceExecution(), $this->qualityDefectFlow(), $this->workforceAdmission()),
            $this->registry(['ordinary_report' => $builtin]),
        );

        self::assertSame(['attendance_execution', 'budget_plan_fact', 'inventory_risk', 'payroll_readiness', 'procurement_cycle', 'project_labor_cost', 'project_margin', 'quality_defect_flow', 'supplier_award_competitiveness', 'supply_reliability', 'workforce_admission', 'workforce_capacity', 'ordinary_report'], $registry->publishedCodes());
        self::assertSame($builtin, $registry->published('ordinary_report'));
    }

    public function test_database_slug_conflicting_with_builtin_is_rejected(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract), $this->projectLaborCost(), $this->payrollReadiness(), $this->workforceCapacity(), $this->procurementCycle(), $this->supplierAward(), $this->supplyReliability(), $this->inventoryRisk(), $this->attendanceExecution(), $this->qualityDefectFlow(), $this->workforceAdmission()),
            $this->registry(['budget_plan_fact' => $builtin]),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_published_definition_conflict');

        $registry->published('budget_plan_fact');
    }

    /** @param array<string, PublishedReportDefinition> $definitions */
    private function registry(array $definitions): ReportDefinitionRegistry
    {
        return new class($definitions) implements ReportDefinitionRegistry
        {
            /** @param array<string, PublishedReportDefinition> $definitions */
            public function __construct(private array $definitions) {}

            public function published(string $code): PublishedReportDefinition
            {
                if (! isset($this->definitions[$code])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
                }

                return $this->definitions[$code];
            }

            public function publishedCodes(): array
            {
                return array_keys($this->definitions);
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('0', 64));
            }
        };
    }

    private function projectMargin(): ProjectMarginBuiltinPublishedReport
    {
        return new ProjectMarginBuiltinPublishedReport(new ProjectMarginCandidateContract);
    }

    private function projectLaborCost(): ProjectLaborCostBuiltinPublishedReport
    {
        return new ProjectLaborCostBuiltinPublishedReport(new ProjectLaborCostCandidateContract);
    }

    private function payrollReadiness(): PayrollReadinessBuiltinPublishedReport
    {
        return new PayrollReadinessBuiltinPublishedReport(new PayrollReadinessCandidateContract);
    }

    private function workforceCapacity(): WorkforceCapacityBuiltinPublishedReport
    {
        return new WorkforceCapacityBuiltinPublishedReport(new WorkforceCapacityCandidateContract);
    }

    private function procurementCycle(): ProcurementCycleBuiltinPublishedReport
    {
        return new ProcurementCycleBuiltinPublishedReport(new ProcurementCycleCandidateContract);
    }

    private function supplierAward(): SupplierAwardBuiltinPublishedReport
    {
        return new SupplierAwardBuiltinPublishedReport(new SupplierAwardCandidateContract);
    }

    private function supplyReliability(): SupplyReliabilityBuiltinPublishedReport
    {
        return new SupplyReliabilityBuiltinPublishedReport(new SupplyReliabilityCandidateContract);
    }

    private function inventoryRisk(): InventoryRiskBuiltinPublishedReport
    {
        return new InventoryRiskBuiltinPublishedReport(new InventoryRiskCandidateContract);
    }

    private function attendanceExecution(): AttendanceExecutionBuiltinPublishedReport
    {
        return new AttendanceExecutionBuiltinPublishedReport(new AttendanceExecutionCandidateContract);
    }

    private function qualityDefectFlow(): QualityDefectFlowBuiltinPublishedReport
    {
        return new QualityDefectFlowBuiltinPublishedReport(new QualityDefectFlowCandidateContract);
    }

    private function workforceAdmission(): WorkforceAdmissionBuiltinPublishedReport
    {
        return new WorkforceAdmissionBuiltinPublishedReport(new WorkforceAdmissionCandidateContract);
    }
}
