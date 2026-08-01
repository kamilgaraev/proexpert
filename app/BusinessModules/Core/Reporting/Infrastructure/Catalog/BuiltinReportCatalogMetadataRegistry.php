<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;

final readonly class BuiltinReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(private BudgetPlanFactBuiltinPublishedReport $budgetPlanFact) {}

    public function published(string $code): ReportCatalogMetadata
    {
        return $code === $this->budgetPlanFact->metadata()->code
            ? $this->budgetPlanFact->metadata()
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }
}
