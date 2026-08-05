<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DrillDown\SupplyReliabilityDrillDownProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Providers\SupplyReliabilityReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Queries\SupplyReliabilityRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness\SupplyReliabilityReadinessProbe;
use InvalidArgumentException;

final readonly class SupplyReliabilityReportBindingFactory
{
    public function __construct(private SupplyReliabilityReportProvider $provider, private SupplyReliabilityRowQuery $rows, private SupplyReliabilityDrillDownProvider $drillDown, private SupplyReliabilityReadinessProbe $readiness) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('supply_reliability_report_definition_not_supported');
        }
        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->rows, $this->drillDown, $this->readiness);
    }
}
