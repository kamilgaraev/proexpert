<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardReportBindingFactory;
use LogicException;

final readonly class SupplierAwardPublishedRuntimeBindingRegistrar
{
    public function __construct(private ReportDefinitionRegistry $definitions, private SupplierAwardReportBindingFactory $bindings) {}

    public function register(ReportDefinitionBindingAssembler $assembler): void
    {
        $definition = $this->definitions->published(SupplierAwardCandidateContract::CODE);
        if ($definition->code !== SupplierAwardCandidateContract::CODE) {
            throw new LogicException('supplier_award_published_definition_invalid');
        }
        $assembler->register($this->bindings->create($definition->payload()));
    }
}
