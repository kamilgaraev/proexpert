<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Definitions;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown\InventoryRiskDrillDownProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Providers\InventoryRiskReportProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Queries\InventoryRiskRowQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Readiness\InventoryRiskReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Award\Providers\SupplierAwardCompetitivenessReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\Readiness\SupplierAwardReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Providers\ProcurementCycleReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries\ProcurementCycleRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Readiness\ProcurementCycleReadinessProbe;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DrillDown\SupplyReliabilityDrillDownProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Providers\SupplyReliabilityReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Queries\SupplyReliabilityRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness\SupplyReliabilityReadinessProbe;
use LogicException;

final class ProductionReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
{
    private array $registered = [];

    public function __construct(
        private readonly ProcurementCycleReportProvider $cycleProvider,
        private readonly ProcurementCycleRowQuery $cycleRows,
        private readonly ProcurementCycleReadinessProbe $cycleReadiness,
        private readonly SupplierAwardCompetitivenessReportProvider $awardProvider,
        private readonly SupplierAwardRowQuery $awardRows,
        private readonly SupplierAwardReadinessProbe $awardReadiness,
        private readonly SupplyReliabilityReportProvider $supplyProvider,
        private readonly SupplyReliabilityRowQuery $supplyRows,
        private readonly SupplyReliabilityDrillDownProvider $supplyDrillDown,
        private readonly SupplyReliabilityReadinessProbe $supplyReadiness,
        private readonly InventoryRiskReportProvider $inventoryProvider,
        private readonly InventoryRiskRowQuery $inventoryRows,
        private readonly InventoryRiskDrillDownProvider $inventoryDrillDown,
        private readonly InventoryRiskReadinessProbe $inventoryReadiness,
    ) {}

    public function register(ReportDefinitionBinding $binding): void
    {
        if (isset($this->registered[$binding->code])) {
            throw new LogicException('report_definition_binding_duplicate');
        }
        $this->registered[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        $bindings = $this->registered;
        foreach ($this->productionComponents() as $code => $components) {
            $definition = $registry->published($code)->payload();
            $binding = new ReportDefinitionBinding(
                $code,
                $definition->definitionHash,
                $definition->contractVersion,
                $components['provider'],
                $components['rows'],
                $components['drill_down'],
                $components['readiness'],
            );
            if (isset($bindings[$code])
                && ($bindings[$code]->definitionHash->value !== $binding->definitionHash->value
                    || $bindings[$code]->contractVersion !== $binding->contractVersion)) {
                throw new LogicException('report_definition_binding_identity_mismatch');
            }
            $bindings[$code] = $binding;
        }

        return new ReportDefinitionBindingMap($bindings);
    }

    private function productionComponents(): array
    {
        return [
            'procurement_cycle' => [
                'provider' => $this->cycleProvider,
                'rows' => $this->cycleRows,
                'drill_down' => $this->cycleRows,
                'readiness' => $this->cycleReadiness,
            ],
            'supplier_award_competitiveness' => [
                'provider' => $this->awardProvider,
                'rows' => $this->awardRows,
                'drill_down' => $this->awardRows,
                'readiness' => $this->awardReadiness,
            ],
            'supply_reliability' => [
                'provider' => $this->supplyProvider,
                'rows' => $this->supplyRows,
                'drill_down' => $this->supplyDrillDown,
                'readiness' => $this->supplyReadiness,
            ],
            'inventory_risk' => [
                'provider' => $this->inventoryProvider,
                'rows' => $this->inventoryRows,
                'drill_down' => $this->inventoryDrillDown,
                'readiness' => $this->inventoryReadiness,
            ],
        ];
    }
}
