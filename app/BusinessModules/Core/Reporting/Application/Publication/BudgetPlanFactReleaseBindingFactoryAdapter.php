<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use InvalidArgumentException;

final readonly class BudgetPlanFactReleaseBindingFactoryAdapter implements ReportPublicationReleaseBindingFactory
{
    public function __construct(private BudgetPlanFactReportBindingFactory $bindings) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if ($definition->code !== BudgetPlanFactCandidateContract::CODE) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }

        return $this->bindings->create($definition);
    }
}
