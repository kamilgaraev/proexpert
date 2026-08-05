<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown\InventoryRiskDrillDownProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Providers\InventoryRiskReportProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Queries\InventoryRiskRowQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Readiness\InventoryRiskReadinessProbe;
use InvalidArgumentException;

final readonly class InventoryRiskReportBindingFactory
{
    public function __construct(
        private InventoryRiskReportProvider $provider,
        private InventoryRiskRowQuery $rows,
        private InventoryRiskDrillDownProvider $drillDown,
        private InventoryRiskReadinessProbe $readiness,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('inventory_risk_report_definition_not_supported');
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
