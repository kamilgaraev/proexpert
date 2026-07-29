<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use InvalidArgumentException;

final readonly class ReportExecutionWatchdogSummary
{
    public function __construct(
        public int $scanned,
        public int $requeued,
        public int $skipped,
        public int $failed,
    ) {
        if (min($scanned, $requeued, $skipped, $failed) < 0 || $scanned !== $requeued + $skipped + $failed) {
            throw new InvalidArgumentException('report_execution_watchdog_summary_invalid');
        }
    }
}
