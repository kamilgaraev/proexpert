<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;

final readonly class BuiltinReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(
        private ProjectMarginBuiltinPublishedReport $projectMargin,
        private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        private ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
    ) {}

    public function published(string $code): ReportCatalogMetadata
    {
        return match ($code) {
            $this->projectMargin->metadata()->code => $this->projectMargin->metadata(),
            $this->budgetPlanFact->metadata()->code => $this->budgetPlanFact->metadata(),
            $this->projectLaborCost->metadata()->code => $this->projectLaborCost->metadata(),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND),
        };
    }
}
