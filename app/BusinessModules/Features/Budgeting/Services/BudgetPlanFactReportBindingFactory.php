<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;

final readonly class BudgetPlanFactReportBindingFactory
{
    public function __construct(
        private PlanFactReportSourceSnapshotAdapter $adapter,
        private BudgetPlanFactCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->adapter,
            $this->adapter,
            $this->adapter,
            null,
        );
    }
}
