<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final class ProcurementCycleReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === ProcurementCycleReportAdapter::REPORT_CODE
            && $definition->contractVersion === '1.0.0'
            && $definition->formulaVersion === ProcurementCycleReportAdapter::FORMULA_VERSION
            && $definition->sourceSchemaVersion === ProcurementCycleReportAdapter::SCHEMA_VERSION
            && $definition->formats === ['csv', 'xlsx', 'pdf']
            && array_column($definition->sorts, 'id') === [ProcurementCycleReportAdapter::SORT_FIELD];
    }
}
