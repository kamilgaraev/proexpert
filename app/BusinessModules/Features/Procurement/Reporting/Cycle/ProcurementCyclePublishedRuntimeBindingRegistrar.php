<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use LogicException;

final readonly class ProcurementCyclePublishedRuntimeBindingRegistrar
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ProcurementCycleReportBindingFactory $bindings,
    ) {}

    public function register(ReportDefinitionBindingAssembler $assembler): void
    {
        $definition = $this->definitions->published(ProcurementCycleCandidateContract::CODE);
        if ($definition->code !== ProcurementCycleCandidateContract::CODE) {
            throw new LogicException('procurement_cycle_published_definition_invalid');
        }

        $assembler->register($this->bindings->create($definition->payload()));
    }
}
