<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

interface GetReportDrillDownAction
{
    public function handle(ReportExecutionContext $context, string $runId, ReportDrillDownRequest $request): ReportDrillDownResult;
}
