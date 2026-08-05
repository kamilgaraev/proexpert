<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DrillDown\ContractorScorecardDrillDownProvider;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Providers\ContractorScorecardReportProvider;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Queries\ContractorScorecardRowQuery;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Readiness\ContractorScorecardReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;

final readonly class ContractorScorecardReportBindingFactory
{
    public function __construct(
        private ContractorScorecardReportProvider $provider,
        private ContractorScorecardRowQuery $query,
        private ContractorScorecardDrillDownProvider $drillDown,
        private ContractorScorecardReadinessProbe $readiness,
        private ContractorScorecardCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);

        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->query, $this->drillDown, $this->readiness);
    }
}
