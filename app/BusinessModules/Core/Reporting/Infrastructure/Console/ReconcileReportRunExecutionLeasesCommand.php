<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExecutionWatchdog;
use Illuminate\Console\Command;

final class ReconcileReportRunExecutionLeasesCommand extends Command
{
    protected $signature = 'reports:runs:reconcile-execution-leases {--limit=100}';

    protected $description = 'Возвращает в очередь запуски отчётов с истёкшей арендой выполнения';

    public function handle(ReportRunExecutionWatchdog $watchdog, ReportExecutionClock $clock): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            return self::INVALID;
        }

        $summary = $watchdog->reclaim($limit, $clock->now());
        $this->line(json_encode([
            'scanned' => $summary->scanned,
            'requeued' => $summary->requeued,
            'skipped' => $summary->skipped,
            'failed' => $summary->failed,
        ], JSON_THROW_ON_ERROR));

        return $summary->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
