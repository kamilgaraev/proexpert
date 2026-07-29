<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditDispatcher;

final class LaravelReportAuditDispatcher implements ReportAuditDispatcher
{
    public function dispatch(string $intentId): void
    {
        AppendReportAuditEventJob::dispatch($intentId)
            ->onConnection('redis_reports')
            ->onQueue('reports-audit');
    }
}
