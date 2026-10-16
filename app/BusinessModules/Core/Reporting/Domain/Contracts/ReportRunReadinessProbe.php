<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;

interface ReportRunReadinessProbe extends ReportDefinitionReadinessProbe
{
    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void;
}
