<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Telemetry;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use Illuminate\Support\Facades\Log;

final class LaravelLogReportExecutionTelemetry implements ReportExecutionTelemetry
{
    public function runTransition(string $reportCode, string $status): void
    {
        $this->record('report.run.transition', compact('reportCode', 'status'));
    }

    public function runDuration(string $reportCode, string $status, float $seconds): void
    {
        $this->record('report.run.duration', compact('reportCode', 'status', 'seconds'));
    }

    public function exportTransition(string $reportCode, string $format, string $status): void
    {
        $this->record('report.export.transition', compact('reportCode', 'format', 'status'));
    }

    public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void
    {
        $this->record('report.export.duration', compact('reportCode', 'format', 'status', 'seconds'));
    }

    public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void
    {
        $this->record('report.export.artifact', compact('reportCode', 'format', 'rows', 'bytes'));
    }

    public function multipartAbort(string $reportCode, string $format): void
    {
        $this->record('report.export.multipart_abort', compact('reportCode', 'format'));
    }

    public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void
    {
        $this->record('report.dispatch.intent', compact('intentType', 'topic', 'outcome', 'ageSeconds'));
    }

    public function executionAttempt(string $intentType, string $errorCode): void
    {
        $this->record('report.execution.attempt', compact('intentType', 'errorCode'));
    }

    public function executionLeaseReclaimed(string $intentType): void
    {
        $this->record('report.execution.lease_reclaimed', compact('intentType'));
    }

    public function auditDeliveryFailure(string $errorCode, string $outcome): void
    {
        $this->record('report.audit.delivery_failure', compact('errorCode', 'outcome'));
    }

    private function record(string $event, array $context): void
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key))] = $value;
        }

        Log::info($event, $normalized);
    }
}
