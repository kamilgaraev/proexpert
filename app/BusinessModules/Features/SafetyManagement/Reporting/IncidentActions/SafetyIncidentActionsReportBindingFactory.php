<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Readiness\SafetyIncidentReadinessProbe;

final readonly class SafetyIncidentActionsReportBindingFactory
{
    public function __construct(
        private SafetyIncidentActionsReportProvider $provider,
        private SafetyIncidentRowQuery $rows,
        private SafetyIncidentDrillDownProvider $drillDown,
        private SafetyIncidentReadinessProbe $readiness,
        private SafetyIncidentActionsCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);
        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->rows, $this->drillDown, $this->readiness);
    }
}
