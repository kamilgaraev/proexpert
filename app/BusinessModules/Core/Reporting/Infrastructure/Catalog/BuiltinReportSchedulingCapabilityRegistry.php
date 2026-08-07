<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimBuiltinPublishedReport;
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

final readonly class BuiltinReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
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
        private ?ChangeClaimBuiltinPublishedReport $changeClaim = null,
    ) {}

    public function published(string $code): ReportSchedulingCapability
    {
        if ($this->lookaheadReadiness !== null
            && $code === $this->lookaheadReadiness->scheduling()->code) {
            return $this->lookaheadReadiness->scheduling();
        }
        if ($this->managementPnl !== null
            && $code === $this->managementPnl->scheduling()->code) {
            return $this->managementPnl->scheduling();
        }
        if ($this->changeClaim !== null
            && $code === $this->changeClaim->scheduling()->code) {
            return $this->changeClaim->scheduling();
        }

        return match ($code) {
            $this->projectMargin->scheduling()->code => $this->projectMargin->scheduling(),
            $this->budgetPlanFact->scheduling()->code => $this->budgetPlanFact->scheduling(),
            $this->portfolioLiquidity->scheduling()->code => $this->portfolioLiquidity->scheduling(),
            $this->projectPortfolioHealth->scheduling()->code => $this->projectPortfolioHealth->scheduling(),
            $this->projectEvmControl->scheduling()->code => $this->projectEvmControl->scheduling(),
            $this->wipCompletionForecast->scheduling()->code => $this->wipCompletionForecast->scheduling(),
            $this->contractSettlementExposure->scheduling()->code => $this->contractSettlementExposure->scheduling(),
            $this->baselineScheduleVariance->scheduling()->code => $this->baselineScheduleVariance->scheduling(),
            $this->acceptedProduction->scheduling()->code => $this->acceptedProduction->scheduling(),
            $this->projectLaborCost->scheduling()->code => $this->projectLaborCost->scheduling(),
            $this->payrollReadiness->scheduling()->code => $this->payrollReadiness->scheduling(),
            $this->workforceCapacity->scheduling()->code => $this->workforceCapacity->scheduling(),
            $this->procurementCycle->scheduling()->code => $this->procurementCycle->scheduling(),
            $this->supplierAward->scheduling()->code => $this->supplierAward->scheduling(),
            $this->supplyReliability->scheduling()->code => $this->supplyReliability->scheduling(),
            $this->inventoryRisk->scheduling()->code => $this->inventoryRisk->scheduling(),
            $this->attendanceExecution->scheduling()->code => $this->attendanceExecution->scheduling(),
            $this->qualityDefectFlow->scheduling()->code => $this->qualityDefectFlow->scheduling(),
            $this->safetyIncidentActions->scheduling()->code => $this->safetyIncidentActions->scheduling(),
            $this->workforceAdmission->scheduling()->code => $this->workforceAdmission->scheduling(),
            $this->handoverReadiness->scheduling()->code => $this->handoverReadiness->scheduling(),
            $this->contractorScorecard->scheduling()->code => $this->contractorScorecard->scheduling(),
            $this->customerSla->scheduling()->code => $this->customerSla->scheduling(),
            $this->holdingPerformance->scheduling()->code => $this->holdingPerformance->scheduling(),
            $this->intercompanyContractFlow->scheduling()->code => $this->intercompanyContractFlow->scheduling(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
