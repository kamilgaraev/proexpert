<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Procurement\Reporting\Award\Providers\SupplierAwardCompetitivenessReportProvider;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardRowQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\Readiness\SupplierAwardReadinessProbe;
use InvalidArgumentException;

final readonly class SupplierAwardReportBindingFactory
{
    public function __construct(
        private SupplierAwardCompetitivenessReportProvider $provider,
        private SupplierAwardRowQuery $rows,
        private SupplierAwardReadinessProbe $readiness,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('supplier_award_report_definition_not_supported');
        }

        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->rows, $this->rows, $this->readiness);
    }
}
