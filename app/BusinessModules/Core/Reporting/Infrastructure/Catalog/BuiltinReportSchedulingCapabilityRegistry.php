<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\Services\Customer\Reporting\Sla\CustomerSlaBuiltinPublishedReport;

final readonly class BuiltinReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    public function __construct(
        private ProjectMarginBuiltinPublishedReport $projectMargin,
        private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        private BaselineScheduleVarianceBuiltinPublishedReport $baselineScheduleVariance,
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
        private CustomerSlaBuiltinPublishedReport $customerSla,
    ) {}

    public function published(string $code): ReportSchedulingCapability
    {
        return match ($code) {
            $this->projectMargin->scheduling()->code => $this->projectMargin->scheduling(),
            $this->budgetPlanFact->scheduling()->code => $this->budgetPlanFact->scheduling(),
            $this->baselineScheduleVariance->scheduling()->code => $this->baselineScheduleVariance->scheduling(),
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
            $this->customerSla->scheduling()->code => $this->customerSla->scheduling(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
