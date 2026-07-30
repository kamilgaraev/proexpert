<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionWatchdog;
use Illuminate\Console\Command;

use function trans_message;

final class ReconcileReportExportExecutionLeasesCommand extends Command
{
    protected $signature = 'reports:exports:reconcile-execution-leases {--limit=}';

    protected $description;

    public function __construct(
        private readonly ReportExportExecutionWatchdog $watchdog,
        private readonly ReportExecutionClock $clock,
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {
        parent::__construct();
        $this->description = trans_message('reports.commands.reconcile_export_execution_leases');
    }

    public function handle(): int
    {
        $option = $this->option('limit');
        $limit = $option === null
            ? $this->runtime->watchdogBatchSize
            : filter_var($option, FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            return self::INVALID;
        }

        $summary = $this->watchdog->reclaim($limit, $this->clock->now());
        $this->line(json_encode([
            'scanned' => $summary->scanned,
            'requeued' => $summary->requeued,
            'skipped' => $summary->skipped,
            'failed' => $summary->failed,
        ], JSON_THROW_ON_ERROR));

        return $summary->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
