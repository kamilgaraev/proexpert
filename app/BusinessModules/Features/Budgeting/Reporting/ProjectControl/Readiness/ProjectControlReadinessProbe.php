<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final readonly class ProjectControlReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'project_evm_control'
            && $definition->formulaVersion === 'project_control_core.v1';
    }
}
