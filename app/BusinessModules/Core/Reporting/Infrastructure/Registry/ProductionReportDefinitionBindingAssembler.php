<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Registry;

use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Readiness\QualityDefectFlowReadinessProbe;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers\WorkforceAdmissionReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Readiness\WorkforceAdmissionReadinessProbe;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Readiness\SafetyIncidentReadinessProbe;
use Illuminate\Contracts\Container\Container;

final class ProductionReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
{
    private array $bindings = [];

    public function __construct(Container $container, CandidateReportDefinitionRegistry $candidates)
    {
        foreach ([
            'quality_defect_flow' => [QualityDefectFlowReportProvider::class, QualityDefectFlowRowQuery::class, QualityDefectFlowDrillDownProvider::class, QualityDefectFlowReadinessProbe::class],
            'safety_incident_actions' => [SafetyIncidentActionsReportProvider::class, SafetyIncidentRowQuery::class, SafetyIncidentDrillDownProvider::class, SafetyIncidentReadinessProbe::class],
            'workforce_admission' => [WorkforceAdmissionReportProvider::class, WorkforceAdmissionRowQuery::class, WorkforceAdmissionDrillDownProvider::class, WorkforceAdmissionReadinessProbe::class],
        ] as $code => [$provider, $rows, $drillDown, $readiness]) {
            $candidate = $candidates->candidate($code);
            $this->register(new ReportDefinitionBinding(
                $code,
                $candidate->definitionHash,
                $candidate->definition->contractVersion,
                $container->make($provider),
                $container->make($rows),
                $container->make($drillDown),
                $container->make($readiness),
            ));
        }
    }

    public function register(ReportDefinitionBinding $binding): void
    {
        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        $resolved = [];
        foreach ($registry->publishedCodes() as $code) {
            $binding = $this->bindings[$code] ?? null;
            if ($binding instanceof ReportDefinitionBinding
                && $binding->definitionHash->value === $registry->published($code)->definitionHash->value) {
                $resolved[$code] = $binding;
            }
        }

        return new ReportDefinitionBindingMap($resolved);
    }
}
