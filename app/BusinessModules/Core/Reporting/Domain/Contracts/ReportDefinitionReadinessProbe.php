<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

interface ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool;
}
