<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

interface ReportRunExecutionContextRehydrator
{
    public function forRun(string $runId): ReportExecutionContext;
}
