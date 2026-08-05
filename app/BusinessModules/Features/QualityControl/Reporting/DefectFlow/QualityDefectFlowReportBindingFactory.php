<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Readiness\QualityDefectFlowReadinessProbe;

final readonly class QualityDefectFlowReportBindingFactory
{
    public function __construct(
        private QualityDefectFlowReportProvider $provider,
        private QualityDefectFlowRowQuery $rows,
        private QualityDefectFlowDrillDownProvider $drillDown,
        private QualityDefectFlowReadinessProbe $readiness,
        private QualityDefectFlowCandidateContract $contract,
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
