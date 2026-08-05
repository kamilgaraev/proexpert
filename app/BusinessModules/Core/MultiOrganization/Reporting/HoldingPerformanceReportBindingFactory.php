<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\HoldingPerformanceReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\HoldingPerformanceRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Readiness\HoldingPerformanceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use InvalidArgumentException;

final readonly class HoldingPerformanceReportBindingFactory
{
    public function __construct(
        private HoldingPerformanceReportProvider $provider,
        private HoldingPerformanceRowQuery $rows,
        private HoldingPerformanceReadinessProbe $readiness,
        private HoldingPerformanceCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('holding_performance_definition_not_supported');
        }

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->provider,
            $this->rows,
            $this->rows,
            $this->readiness,
        );
    }
}
