<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;

interface ReportFilterInputNormalizer
{
    public function normalize(ReportExecutionContext $context, ReportDefinition $definition, array $input): ?ReportFilterSet;

    public function forExecution(ReportExecutionContext $context, ReportDefinition $definition, ReportFilterSet $filters): ReportFilterSet;
}
