<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;

interface GetReportRowsAction
{
    public function handle(ReportExecutionContext $context, string $runId, ReportRowsWindow $window): ReportPage;
}
