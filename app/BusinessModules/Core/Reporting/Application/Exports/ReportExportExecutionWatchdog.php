<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionWatchdogSummary;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ReportExportExecutionWatchdog
{
    public function __construct(
        private ReportExportLeaseRecoveryStore $store,
        private ReportExecutionTelemetry $telemetry,
    ) {}

    public function reclaim(int $limit, DateTimeImmutable $occurredAt): ReportExecutionWatchdogSummary
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('report_execution_watchdog_limit_invalid');
        }

        $requeued = 0;
        $skipped = 0;
        $failed = 0;
        $leases = $this->store->due($limit, $occurredAt);
        foreach ($leases as $lease) {
            try {
                if ($this->store->requeue($lease, $occurredAt)) {
                    ++$requeued;
                    $this->telemetry->executionLeaseReclaimed('export');
                } else {
                    ++$skipped;
                }
            } catch (Throwable) {
                ++$failed;
            }
        }

        return new ReportExecutionWatchdogSummary(count($leases), $requeued, $skipped, $failed);
    }
}
