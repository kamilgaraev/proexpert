<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;

final readonly class ProjectPortfolioHealthReportBindingFactory
{
    public function __construct(
        private ProjectPortfolioHealthProvider $provider,
        private BudgetingPortfolioQueryService $query,
        private ProjectPortfolioHealthReadinessProbe $readiness,
        private ProjectPortfolioHealthCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->provider,
            $this->query,
            $this->query,
            $this->readiness,
        );
    }
}
