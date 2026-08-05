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
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;

final readonly class BuiltinPublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    /** @var array<string, PublishedReportDefinition> */
    private array $definitions;

    public function __construct(
        ProjectMarginBuiltinPublishedReport $projectMargin,
        BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
        PayrollReadinessBuiltinPublishedReport $payrollReadiness,
    ) {
        $byCode = [];
        foreach ([$projectMargin->definition(), $budgetPlanFact->definition(), $projectLaborCost->definition(), $payrollReadiness->definition()] as $definition) {
            $byCode[$definition->code] = $definition;
        }
        ksort($byCode, SORT_STRING);
        $this->definitions = $byCode;
    }

    public function published(string $code): PublishedReportDefinition
    {
        return $this->definitions[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return array_keys($this->definitions);
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode(array_map(
            static fn (PublishedReportDefinition $definition): array => [
                'code' => $definition->code,
                'definition_sha256' => $definition->definitionHash->value,
            ],
            array_values($this->definitions),
        ))));
    }
}
