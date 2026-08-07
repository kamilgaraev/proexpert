<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown\LookaheadReadinessDrillDownProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Providers\LookaheadReadinessReportProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries\LookaheadReadinessRowQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;

final readonly class LookaheadReadinessReportBindingFactory
{
    public function __construct(
        private LookaheadReadinessReportProvider $provider,
        private LookaheadReadinessRowQuery $rows,
        private LookaheadReadinessDrillDownProvider $drillDown,
        private LookaheadReadinessProbe $readiness,
        private LookaheadReadinessCandidateContract $contract,
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
            $this->rows,
            $this->drillDown,
            $this->readiness,
        );
    }
}
