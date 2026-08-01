<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use InvalidArgumentException;

final readonly class ProcurementCycleReportBindingFactory
{
    public function __construct(
        private ProcurementCycleReportAdapter $adapter,
        private ProcurementCycleReadinessProbe $readiness,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('procurement_cycle_report_definition_not_supported');
        }

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->adapter,
            $this->adapter,
            $this->adapter,
            $this->readiness,
        );
    }
}
