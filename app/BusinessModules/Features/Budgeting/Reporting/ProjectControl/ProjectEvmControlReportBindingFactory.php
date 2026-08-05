<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DrillDown\ProjectEvmControlDrillDownProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Providers\ProjectEvmControlReportProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Queries\ProjectEvmControlRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness\ProjectControlReadinessProbe;
use InvalidArgumentException;

final readonly class ProjectEvmControlReportBindingFactory
{
    public function __construct(
        private ProjectEvmControlReportProvider $provider,
        private ProjectEvmControlRowQuery $rows,
        private ProjectEvmControlDrillDownProvider $drillDown,
        private ProjectControlReadinessProbe $readiness,
        private ProjectEvmControlCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('project_evm_control_report_definition_not_supported');
        }

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->provider,
            $this->rows,
            $this->drillDown,
            $this->readiness,
        );
    }
}
