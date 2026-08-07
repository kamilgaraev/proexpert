<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;

final readonly class ManagementPnlReportBindingFactory
{
    public function __construct(
        private ManagementPnlProvider $provider,
        private ManagementPnlQueryService $query,
        private ManagementPnlReadinessProbe $readiness,
        private ManagementPnlCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);

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
