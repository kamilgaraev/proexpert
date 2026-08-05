<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness\BaselineScheduleVarianceReadinessProbe;

final readonly class BaselineScheduleVarianceReportBindingFactory
{
    public function __construct(
        private BaselineScheduleVarianceProvider $provider,
        private BaselineScheduleVarianceQueryService $query,
        private BaselineScheduleVarianceReadinessProbe $readiness,
        private BaselineScheduleVarianceCandidateContract $contract,
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
