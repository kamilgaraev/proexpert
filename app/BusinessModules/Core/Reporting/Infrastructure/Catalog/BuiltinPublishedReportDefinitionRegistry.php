<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;

final readonly class BuiltinPublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    private PublishedReportDefinition $budgetPlanFact;

    public function __construct(BudgetPlanFactBuiltinPublishedReport $budgetPlanFact)
    {
        $this->budgetPlanFact = $budgetPlanFact->definition();
    }

    public function published(string $code): PublishedReportDefinition
    {
        return $code === $this->budgetPlanFact->code
            ? $this->budgetPlanFact
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [$this->budgetPlanFact->code];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            ['code' => $this->budgetPlanFact->code, 'definition_sha256' => $this->budgetPlanFact->definitionHash->value],
        ])));
    }
}
