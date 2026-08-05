<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
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

final readonly class BuiltinReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
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
    ) {}

    public function published(string $code): ReportCatalogMetadata
    {
        return match ($code) {
            $this->projectMargin->metadata()->code => $this->projectMargin->metadata(),
            $this->budgetPlanFact->metadata()->code => $this->budgetPlanFact->metadata(),
            $this->baselineScheduleVariance->metadata()->code => $this->baselineScheduleVariance->metadata(),
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
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
