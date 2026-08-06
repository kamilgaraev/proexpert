<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Queries\AcceptedProductionRowQuery;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness\AcceptedProductionReadinessProbe;
use InvalidArgumentException;

final readonly class AcceptedProductionReportBindingFactory
{
    public function __construct(
        private AcceptedProductionReportProvider $provider,
        private AcceptedProductionRowQuery $rows,
        private AcceptedProductionDrillDownProvider $drillDown,
        private AcceptedProductionReadinessProbe $readiness,
        private AcceptedProductionCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('accepted_production_report_definition_not_supported');
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
