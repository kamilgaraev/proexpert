<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;

final readonly class BuiltinReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    public function __construct(private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact) {}

    public function published(string $code): ReportSchedulingCapability
    {
        return $code === $this->budgetPlanFact->scheduling()->code
            ? $this->budgetPlanFact->scheduling()
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }
}
