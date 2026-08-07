<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness\ChangeClaimReadinessProbe;

final readonly class ChangeClaimReportBindingFactory
{
    public function __construct(
        private ChangeClaimContingencyReportProvider $provider,
        private ChangeClaimRowQuery $query,
        private ChangeClaimDrillDownProvider $drillDown,
        private ChangeClaimReadinessProbe $readiness,
        private ChangeClaimCandidateContract $contract,
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
            $this->drillDown,
            $this->readiness,
        );
    }
}
