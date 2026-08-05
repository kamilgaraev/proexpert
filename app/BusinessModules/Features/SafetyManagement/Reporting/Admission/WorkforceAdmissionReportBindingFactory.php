<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers\WorkforceAdmissionReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Readiness\WorkforceAdmissionReadinessProbe;

final readonly class WorkforceAdmissionReportBindingFactory
{
    public function __construct(
        private WorkforceAdmissionReportProvider $provider,
        private WorkforceAdmissionRowQuery $rows,
        private WorkforceAdmissionDrillDownProvider $drillDown,
        private WorkforceAdmissionReadinessProbe $readiness,
        private WorkforceAdmissionCandidateContract $contract,
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
