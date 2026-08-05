<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;

final readonly class BuiltinReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(
        private ProjectMarginBuiltinPublishedReport $projectMargin,
        private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        private ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
        private PayrollReadinessBuiltinPublishedReport $payrollReadiness,
        private WorkforceCapacityBuiltinPublishedReport $workforceCapacity,
        private ProcurementCycleBuiltinPublishedReport $procurementCycle,
        private SupplierAwardBuiltinPublishedReport $supplierAward,
    ) {}

    public function published(string $code): ReportCatalogMetadata
    {
        return match ($code) {
            $this->projectMargin->metadata()->code => $this->projectMargin->metadata(),
            $this->budgetPlanFact->metadata()->code => $this->budgetPlanFact->metadata(),
            $this->projectLaborCost->metadata()->code => $this->projectLaborCost->metadata(),
            $this->payrollReadiness->metadata()->code => $this->payrollReadiness->metadata(),
            $this->workforceCapacity->metadata()->code => $this->workforceCapacity->metadata(),
            $this->procurementCycle->metadata()->code => $this->procurementCycle->metadata(),
            $this->supplierAward->metadata()->code => $this->supplierAward->metadata(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
