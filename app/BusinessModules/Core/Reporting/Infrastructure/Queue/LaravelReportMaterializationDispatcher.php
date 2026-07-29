<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Queue;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;

final class LaravelReportMaterializationDispatcher implements ReportMaterializationDispatcher
{
    public function dispatch(string $runId): void
    {
        MaterializeReportRunJob::dispatch($runId)
            ->onConnection('redis_reports')
            ->onQueue('reports');
    }
}
