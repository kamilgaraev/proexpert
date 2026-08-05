<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;

final readonly class BuiltinReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    public function __construct(
        private ProjectMarginBuiltinPublishedReport $projectMargin,
        private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        private ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
        private PayrollReadinessBuiltinPublishedReport $payrollReadiness,
        private WorkforceCapacityBuiltinPublishedReport $workforceCapacity,
    ) {}

    public function published(string $code): ReportSchedulingCapability
    {
        return match ($code) {
            $this->projectMargin->scheduling()->code => $this->projectMargin->scheduling(),
            $this->budgetPlanFact->scheduling()->code => $this->budgetPlanFact->scheduling(),
            $this->projectLaborCost->scheduling()->code => $this->projectLaborCost->scheduling(),
            $this->payrollReadiness->scheduling()->code => $this->payrollReadiness->scheduling(),
            $this->workforceCapacity->scheduling()->code => $this->workforceCapacity->scheduling(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
