<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceCandidateContract;
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
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
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
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceCandidateContract;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessCandidateContract;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardBuiltinPublishedReport;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardCandidateContract;
use App\Services\Customer\Reporting\Sla\CustomerSlaBuiltinPublishedReport;
use App\Services\Customer\Reporting\Sla\CustomerSlaCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
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
        self::assertSame('portfolio_liquidity', $registry->published('portfolio_liquidity')->code);
        self::assertSame('project_evm_control', $registry->published('project_evm_control')->code);
        self::assertSame('wip_completion_forecast', $registry->published('wip_completion_forecast')->code);
        self::assertSame('contract_settlement_exposure', $registry->published('contract_settlement_exposure')->code);
        self::assertSame('baseline_schedule_variance', $registry->published('baseline_schedule_variance')->code);
        self::assertSame('accepted_production_progress', $registry->published('accepted_production_progress')->code);
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
        self::assertSame('safety_incident_actions', $registry->published('safety_incident_actions')->code);
        self::assertSame('customer_sla', $registry->published('customer_sla')->code);
        self::assertSame('handover_readiness', $registry->published('handover_readiness')->code);
        self::assertSame('contractor_scorecard', $registry->published('contractor_scorecard')->code);
        self::assertSame('holding_performance', $registry->published('holding_performance')->code);
        self::assertSame('intercompany_contract_flows', $registry->published('intercompany_contract_flows')->code);
        self::assertSame('project_margin', $app->make(ReportCatalogMetadataRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportCatalogMetadataRegistry::class)->published('budget_plan_fact')->code);
        self::assertSame('portfolio_liquidity', $app->make(ReportCatalogMetadataRegistry::class)->published('portfolio_liquidity')->code);
        self::assertSame('project_evm_control', $app->make(ReportCatalogMetadataRegistry::class)->published('project_evm_control')->code);
        self::assertSame('wip_completion_forecast', $app->make(ReportCatalogMetadataRegistry::class)->published('wip_completion_forecast')->code);
        self::assertSame('contract_settlement_exposure', $app->make(ReportCatalogMetadataRegistry::class)->published('contract_settlement_exposure')->code);
        self::assertSame('baseline_schedule_variance', $app->make(ReportCatalogMetadataRegistry::class)->published('baseline_schedule_variance')->code);
        self::assertSame('accepted_production_progress', $app->make(ReportCatalogMetadataRegistry::class)->published('accepted_production_progress')->code);
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
        self::assertSame('safety_incident_actions', $app->make(ReportCatalogMetadataRegistry::class)->published('safety_incident_actions')->code);
        self::assertSame('customer_sla', $app->make(ReportCatalogMetadataRegistry::class)->published('customer_sla')->code);
        self::assertSame('contractor_scorecard', $app->make(ReportCatalogMetadataRegistry::class)->published('contractor_scorecard')->code);
        self::assertSame('holding_performance', $app->make(ReportCatalogMetadataRegistry::class)->published('holding_performance')->code);
        self::assertSame('intercompany_contract_flows', $app->make(ReportCatalogMetadataRegistry::class)->published('intercompany_contract_flows')->code);
        self::assertSame('project_margin', $app->make(ReportSchedulingCapabilityRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportSchedulingCapabilityRegistry::class)->published('budget_plan_fact')->code);
        self::assertSame('portfolio_liquidity', $app->make(ReportSchedulingCapabilityRegistry::class)->published('portfolio_liquidity')->code);
        self::assertSame('project_evm_control', $app->make(ReportSchedulingCapabilityRegistry::class)->published('project_evm_control')->code);
        self::assertSame('wip_completion_forecast', $app->make(ReportSchedulingCapabilityRegistry::class)->published('wip_completion_forecast')->code);
        self::assertSame('contract_settlement_exposure', $app->make(ReportSchedulingCapabilityRegistry::class)->published('contract_settlement_exposure')->code);
        self::assertSame('baseline_schedule_variance', $app->make(ReportSchedulingCapabilityRegistry::class)->published('baseline_schedule_variance')->code);
        self::assertSame('accepted_production_progress', $app->make(ReportSchedulingCapabilityRegistry::class)->published('accepted_production_progress')->code);
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
        self::assertSame('safety_incident_actions', $app->make(ReportSchedulingCapabilityRegistry::class)->published('safety_incident_actions')->code);
        self::assertSame('customer_sla', $app->make(ReportSchedulingCapabilityRegistry::class)->published('customer_sla')->code);
        self::assertSame('contractor_scorecard', $app->make(ReportSchedulingCapabilityRegistry::class)->published('contractor_scorecard')->code);
        self::assertSame('holding_performance', $app->make(ReportSchedulingCapabilityRegistry::class)->published('holding_performance')->code);
        self::assertSame('intercompany_contract_flows', $app->make(ReportSchedulingCapabilityRegistry::class)->published('intercompany_contract_flows')->code);
    }

    public function test_provider_composes_metadata_for_every_published_builtin_report(): void
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

        $definitions = $app->make(ReportDefinitionRegistry::class);
        $metadata = $app->make(ReportCatalogMetadataRegistry::class);
        $ordinals = [];

        foreach ($definitions->publishedCodes() as $code) {
            $entry = $metadata->published($code);
            self::assertSame($code, $entry->code);
            $ordinals[] = $entry->manifestOrdinal;
        }

        self::assertSame(count($ordinals), count(array_unique($ordinals)));
        self::assertContains(CustomerSlaCandidateContract::CODE, $definitions->publishedCodes());
        self::assertContains(28, $ordinals);
    }

    public function test_budget_plan_fact_is_available_without_database_publication(): void
    {
        $builtins = new BuiltinPublishedReportDefinitionRegistry(
            $this->projectMargin(),
            new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract),
            $this->portfolioLiquidity(),
            $this->projectEvmControl(),
            $this->wipCompletionForecast(),
            $this->contractSettlementExposure(),
            $this->baselineScheduleVariance(),
            $this->acceptedProduction(),
            $this->projectLaborCost(),
            $this->payrollReadiness(),
            $this->workforceCapacity(),
            $this->procurementCycle(),
            $this->supplierAward(),
            $this->supplyReliability(),
            $this->inventoryRisk(),
            $this->attendanceExecution(),
            $this->qualityDefectFlow(),
            $this->safetyIncidentActions(),
            $this->workforceAdmission(),
            $this->handoverReadiness(),
            $this->contractorScorecard(),
            $this->customerSla(),
            $this->holdingPerformance(),
            $this->intercompanyContractFlow(),
        );
        $registry = new CompositePublishedReportDefinitionRegistry($builtins, $this->registry([]));

        self::assertSame(['accepted_production_progress', 'attendance_execution', 'baseline_schedule_variance', 'budget_plan_fact', 'contract_settlement_exposure', 'contractor_scorecard', 'customer_sla', 'handover_readiness', 'holding_performance', 'intercompany_contract_flows', 'inventory_risk', 'payroll_readiness', 'portfolio_liquidity', 'procurement_cycle', 'project_evm_control', 'project_labor_cost', 'project_margin', 'quality_defect_flow', 'safety_incident_actions', 'supplier_award_competitiveness', 'supply_reliability', 'wip_completion_forecast', 'workforce_admission', 'workforce_capacity'], $registry->publishedCodes());
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
            $this->portfolioLiquidity(),
            $this->projectEvmControl(),
            $this->wipCompletionForecast(),
            $this->contractSettlementExposure(),
            $this->baselineScheduleVariance(),
            $this->acceptedProduction(),
            $this->projectLaborCost(),
            $this->payrollReadiness(),
            $this->workforceCapacity(),
            $this->procurementCycle(),
            $this->supplierAward(),
            $this->supplyReliability(),
            $this->inventoryRisk(),
            $this->attendanceExecution(),
            $this->qualityDefectFlow(),
            $this->safetyIncidentActions(),
            $this->workforceAdmission(),
            $this->handoverReadiness(),
            $this->contractorScorecard(),
            $this->customerSla(),
            $this->holdingPerformance(),
            $this->intercompanyContractFlow(),
        );

        $expectedModules = [
            'budget_plan_fact' => 'budgeting',
            'portfolio_liquidity' => 'budgeting',
            'project_evm_control' => 'budgeting',
            'wip_completion_forecast' => 'budgeting',
            'contract_settlement_exposure' => 'contract-management',
            'baseline_schedule_variance' => 'schedule-management',
            'accepted_production_progress' => 'contract-management',
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
            'safety_incident_actions' => 'safety-management',
            'workforce_admission' => 'safety-management',
            'customer_sla' => 'reports',
            'contractor_scorecard' => 'contractor-portal',
            'holding_performance' => 'multi-organization',
            'intercompany_contract_flows' => 'multi-organization',
        ];

        foreach ($expectedModules as $code => $module) {
            $definition = $registry->published($code)->payload();

            self::assertSame($module, $definition->sourceModule);
            self::assertSame(
                $code === 'customer_sla' ? ReportCoreAccessMode::REPORTING_WORKSPACE : ReportCoreAccessMode::SOURCE_MODULE_REPORT,
                $definition->coreAccessMode,
            );
        }
    }

    public function test_database_published_report_remains_available(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract), $this->portfolioLiquidity(), $this->projectEvmControl(), $this->wipCompletionForecast(), $this->contractSettlementExposure(), $this->baselineScheduleVariance(), $this->acceptedProduction(), $this->projectLaborCost(), $this->payrollReadiness(), $this->workforceCapacity(), $this->procurementCycle(), $this->supplierAward(), $this->supplyReliability(), $this->inventoryRisk(), $this->attendanceExecution(), $this->qualityDefectFlow(), $this->safetyIncidentActions(), $this->workforceAdmission(), $this->handoverReadiness(), $this->contractorScorecard(), $this->customerSla(), $this->holdingPerformance(), $this->intercompanyContractFlow()),
            $this->registry(['ordinary_report' => $builtin]),
        );

        self::assertSame(['accepted_production_progress', 'attendance_execution', 'baseline_schedule_variance', 'budget_plan_fact', 'contract_settlement_exposure', 'contractor_scorecard', 'customer_sla', 'handover_readiness', 'holding_performance', 'intercompany_contract_flows', 'inventory_risk', 'payroll_readiness', 'portfolio_liquidity', 'procurement_cycle', 'project_evm_control', 'project_labor_cost', 'project_margin', 'quality_defect_flow', 'safety_incident_actions', 'supplier_award_competitiveness', 'supply_reliability', 'wip_completion_forecast', 'workforce_admission', 'workforce_capacity', 'ordinary_report'], $registry->publishedCodes());
        self::assertSame($builtin, $registry->published('ordinary_report'));
    }

    public function test_database_slug_conflicting_with_builtin_is_rejected(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract), $this->portfolioLiquidity(), $this->projectEvmControl(), $this->wipCompletionForecast(), $this->contractSettlementExposure(), $this->baselineScheduleVariance(), $this->acceptedProduction(), $this->projectLaborCost(), $this->payrollReadiness(), $this->workforceCapacity(), $this->procurementCycle(), $this->supplierAward(), $this->supplyReliability(), $this->inventoryRisk(), $this->attendanceExecution(), $this->qualityDefectFlow(), $this->safetyIncidentActions(), $this->workforceAdmission(), $this->handoverReadiness(), $this->contractorScorecard(), $this->customerSla(), $this->holdingPerformance(), $this->intercompanyContractFlow()),
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

    private function portfolioLiquidity(): PortfolioLiquidityBuiltinPublishedReport
    {
        return new PortfolioLiquidityBuiltinPublishedReport(new PortfolioLiquidityCandidateContract);
    }

    private function projectEvmControl(): ProjectEvmControlBuiltinPublishedReport
    {
        return new ProjectEvmControlBuiltinPublishedReport(new ProjectEvmControlCandidateContract);
    }

    private function wipCompletionForecast(): WipCompletionForecastBuiltinPublishedReport
    {
        return new WipCompletionForecastBuiltinPublishedReport(new WipCompletionForecastCandidateContract);
    }

    private function contractSettlementExposure(): ContractSettlementExposureBuiltinPublishedReport
    {
        return new ContractSettlementExposureBuiltinPublishedReport(new ContractSettlementExposureCandidateContract);
    }

    private function projectLaborCost(): ProjectLaborCostBuiltinPublishedReport
    {
        return new ProjectLaborCostBuiltinPublishedReport(new ProjectLaborCostCandidateContract);
    }

    private function baselineScheduleVariance(): BaselineScheduleVarianceBuiltinPublishedReport
    {
        return new BaselineScheduleVarianceBuiltinPublishedReport(new BaselineScheduleVarianceCandidateContract);
    }

    private function acceptedProduction(): AcceptedProductionBuiltinPublishedReport
    {
        return new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract);
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

    private function safetyIncidentActions(): SafetyIncidentActionsBuiltinPublishedReport
    {
        return new SafetyIncidentActionsBuiltinPublishedReport(new SafetyIncidentActionsCandidateContract);
    }

    private function customerSla(): CustomerSlaBuiltinPublishedReport
    {
        return new CustomerSlaBuiltinPublishedReport(new CustomerSlaCandidateContract);
    }

    private function handoverReadiness(): HandoverReadinessBuiltinPublishedReport
    {
        return new HandoverReadinessBuiltinPublishedReport(new HandoverReadinessCandidateContract);
    }

    private function contractorScorecard(): ContractorScorecardBuiltinPublishedReport
    {
        return new ContractorScorecardBuiltinPublishedReport(new ContractorScorecardCandidateContract);
    }

    private function intercompanyContractFlow(): IntercompanyContractFlowBuiltinPublishedReport
    {
        return new IntercompanyContractFlowBuiltinPublishedReport(new IntercompanyContractFlowCandidateContract);
    }

    private function holdingPerformance(): HoldingPerformanceBuiltinPublishedReport
    {
        return new HoldingPerformanceBuiltinPublishedReport(new HoldingPerformanceCandidateContract);
    }
}
