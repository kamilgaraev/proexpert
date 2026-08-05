<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilityReportBindingFactory;
use LogicException;

final readonly class SupplyReliabilityPublishedRuntimeBindingRegistrar
{
    public function __construct(private ReportDefinitionRegistry $definitions, private SupplyReliabilityReportBindingFactory $bindings) {}

    public function register(ReportDefinitionBindingAssembler $assembler): void
    {
        $definition = $this->definitions->published(SupplyReliabilityCandidateContract::CODE);
        if ($definition->code !== SupplyReliabilityCandidateContract::CODE) {
            throw new LogicException('supply_reliability_published_definition_invalid');
        }
        $assembler->register($this->bindings->create($definition->payload()));
    }
}
