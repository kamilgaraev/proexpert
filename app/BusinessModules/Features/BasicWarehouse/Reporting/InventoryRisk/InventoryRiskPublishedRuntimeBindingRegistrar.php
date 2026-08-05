<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskReportBindingFactory;
use LogicException;

final readonly class InventoryRiskPublishedRuntimeBindingRegistrar
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private InventoryRiskReportBindingFactory $bindings,
    ) {}

    public function register(ReportDefinitionBindingAssembler $assembler): void
    {
        $definition = $this->definitions->published(InventoryRiskCandidateContract::CODE);
        if ($definition->code !== InventoryRiskCandidateContract::CODE) {
            throw new LogicException('inventory_risk_published_definition_invalid');
        }

        $assembler->register($this->bindings->create($definition->payload()));
    }
}
