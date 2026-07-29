<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Queue;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;

final class LaravelReportExportDispatcher implements ReportExportDispatcher
{
    public function dispatch(string $exportId): void
    {
        GenerateReportExportJob::dispatch($exportId)
            ->onConnection('redis_reports')
            ->onQueue('reports');
    }
}
