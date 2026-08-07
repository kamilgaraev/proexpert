<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityBuiltinPublishedReport;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\Customer\Reporting\Sla\CustomerSlaBuiltinPublishedReport;

final readonly class BuiltinReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(
        private ProjectMarginBuiltinPublishedReport $projectMargin,
        private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        private PortfolioLiquidityBuiltinPublishedReport $portfolioLiquidity,
        private ProjectPortfolioHealthBuiltinPublishedReport $projectPortfolioHealth,
        private ProjectEvmControlBuiltinPublishedReport $projectEvmControl,
        private WipCompletionForecastBuiltinPublishedReport $wipCompletionForecast,
        private ContractSettlementExposureBuiltinPublishedReport $contractSettlementExposure,
        private BaselineScheduleVarianceBuiltinPublishedReport $baselineScheduleVariance,
        private AcceptedProductionBuiltinPublishedReport $acceptedProduction,
        private ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
        private PayrollReadinessBuiltinPublishedReport $payrollReadiness,
        private WorkforceCapacityBuiltinPublishedReport $workforceCapacity,
        private ProcurementCycleBuiltinPublishedReport $procurementCycle,
        private SupplierAwardBuiltinPublishedReport $supplierAward,
        private SupplyReliabilityBuiltinPublishedReport $supplyReliability,
        private InventoryRiskBuiltinPublishedReport $inventoryRisk,
        private AttendanceExecutionBuiltinPublishedReport $attendanceExecution,
        private QualityDefectFlowBuiltinPublishedReport $qualityDefectFlow,
        private SafetyIncidentActionsBuiltinPublishedReport $safetyIncidentActions,
        private WorkforceAdmissionBuiltinPublishedReport $workforceAdmission,
        private HandoverReadinessBuiltinPublishedReport $handoverReadiness,
        private ContractorScorecardBuiltinPublishedReport $contractorScorecard,
        private CustomerSlaBuiltinPublishedReport $customerSla,
        private HoldingPerformanceBuiltinPublishedReport $holdingPerformance,
        private IntercompanyContractFlowBuiltinPublishedReport $intercompanyContractFlow,
        private ?LookaheadReadinessBuiltinPublishedReport $lookaheadReadiness = null,
        private ?ManagementPnlBuiltinPublishedReport $managementPnl = null,
    ) {}

    public function published(string $code): ReportCatalogMetadata
    {
        if ($this->lookaheadReadiness !== null
            && $code === $this->lookaheadReadiness->metadata()->code) {
            return $this->lookaheadReadiness->metadata();
        }
        if ($this->managementPnl !== null
            && $code === $this->managementPnl->metadata()->code) {
            return $this->managementPnl->metadata();
        }

        return match ($code) {
            $this->projectMargin->metadata()->code => $this->projectMargin->metadata(),
            $this->budgetPlanFact->metadata()->code => $this->budgetPlanFact->metadata(),
            $this->portfolioLiquidity->metadata()->code => $this->portfolioLiquidity->metadata(),
            $this->projectPortfolioHealth->metadata()->code => $this->projectPortfolioHealth->metadata(),
            $this->projectEvmControl->metadata()->code => $this->projectEvmControl->metadata(),
            $this->wipCompletionForecast->metadata()->code => $this->wipCompletionForecast->metadata(),
            $this->contractSettlementExposure->metadata()->code => $this->contractSettlementExposure->metadata(),
            $this->baselineScheduleVariance->metadata()->code => $this->baselineScheduleVariance->metadata(),
            $this->acceptedProduction->metadata()->code => $this->acceptedProduction->metadata(),
            $this->projectLaborCost->metadata()->code => $this->projectLaborCost->metadata(),
            $this->payrollReadiness->metadata()->code => $this->payrollReadiness->metadata(),
            $this->workforceCapacity->metadata()->code => $this->workforceCapacity->metadata(),
            $this->procurementCycle->metadata()->code => $this->procurementCycle->metadata(),
            $this->supplierAward->metadata()->code => $this->supplierAward->metadata(),
            $this->supplyReliability->metadata()->code => $this->supplyReliability->metadata(),
            $this->inventoryRisk->metadata()->code => $this->inventoryRisk->metadata(),
            $this->attendanceExecution->metadata()->code => $this->attendanceExecution->metadata(),
            $this->qualityDefectFlow->metadata()->code => $this->qualityDefectFlow->metadata(),
            $this->safetyIncidentActions->metadata()->code => $this->safetyIncidentActions->metadata(),
            $this->workforceAdmission->metadata()->code => $this->workforceAdmission->metadata(),
            $this->handoverReadiness->metadata()->code => $this->handoverReadiness->metadata(),
            $this->contractorScorecard->metadata()->code => $this->contractorScorecard->metadata(),
            $this->customerSla->metadata()->code => $this->customerSla->metadata(),
            $this->holdingPerformance->metadata()->code => $this->holdingPerformance->metadata(),
            $this->intercompanyContractFlow->metadata()->code => $this->intercompanyContractFlow->metadata(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
