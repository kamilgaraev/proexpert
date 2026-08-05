<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DrillDown\HandoverReadinessDrillDownProvider;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Providers\HandoverReadinessReportProvider;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Queries\HandoverReadinessRowQuery;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Readiness\HandoverReadinessProbe;

final readonly class HandoverReadinessReportBindingFactory
{
    public function __construct(private HandoverReadinessReportProvider $provider, private HandoverReadinessRowQuery $query, private HandoverReadinessDrillDownProvider $drillDown, private HandoverReadinessProbe $readiness, private HandoverReadinessCandidateContract $contract) {}
    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches(); $this->contract->assertDefinition($definition); return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->query, $this->drillDown, $this->readiness);
    }
}
