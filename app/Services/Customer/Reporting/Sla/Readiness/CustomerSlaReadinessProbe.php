<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final readonly class CustomerSlaReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'customer_sla'
            && $definition->formulaVersion === 'customer-sla.v1'
            && $definition->sourceSchemaVersion === 'customer-sla.v1';
    }
}
