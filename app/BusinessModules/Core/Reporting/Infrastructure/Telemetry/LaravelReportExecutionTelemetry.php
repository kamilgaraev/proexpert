<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Telemetry;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class LaravelReportExecutionTelemetry implements ReportExecutionTelemetry
{
    private const RUN_STATUSES = ['queued', 'materializing', 'ready', 'failed', 'cancelled', 'expired'];

    private const EXPORT_STATUSES = ['queued', 'running', 'uploading', 'ready', 'failed', 'cancelled', 'expired'];

    private const FORMATS = ['csv', 'xlsx', 'pdf'];

    private const INTENT_TYPES = ['run', 'export', 'audit'];

    private const TOPICS = ['materialize_run', 'generate_export', 'append_audit'];

    private const OUTCOMES = ['pending', 'published', 'delivered', 'retry', 'failed', 'dead_letter', 'reclaimed'];

    public function __construct(
        private LoggerInterface $logger,
        private array $alertThresholds,
        private ReportExecutionAlertWindow $alerts,
    ) {}

    public function runTransition(string $reportCode, string $status): void
    {
        $this->assertReportCode($reportCode);
        $this->assertOneOf($status, self::RUN_STATUSES, 'report_telemetry_run_status_invalid');
        $labels = ['report_code' => $reportCode, 'status' => $status];
        $this->metric('reports_run_total', 1, $labels);
        if ($status === 'failed') {
            $this->metric('reports_run_failed_total', 1, $labels);
        }
        if (in_array($status, ['ready', 'failed'], true)) {
            $this->executionRatio('run', $status === 'failed');
        }
    }

    public function runDuration(string $reportCode, string $status, float $seconds): void
    {
        $this->assertReportCode($reportCode);
        $this->assertOneOf($status, self::RUN_STATUSES, 'report_telemetry_run_status_invalid');
        $this->metric(
            'reports_run_duration_seconds',
            $this->nonNegative($seconds),
            ['report_code' => $reportCode, 'status' => $status, 'duration_bucket' => $this->durationBucket($seconds)],
        );
        $this->durationAlert("run:{$reportCode}:{$status}", $seconds, [
            'report_code' => $reportCode,
            'status' => $status,
            'duration_seconds' => $seconds,
            'regression_ratio_threshold' => $this->alertThresholds['duration_regression_ratio'] ?? null,
        ]);
    }

    public function exportTransition(string $reportCode, string $format, string $status): void
    {
        $this->assertExportDimensions($reportCode, $format, $status);
        $labels = ['report_code' => $reportCode, 'format' => $format, 'status' => $status];
        $this->metric('reports_export_total', 1, $labels);
        if ($status === 'failed') {
            $this->metric('reports_export_failed_total', 1, $labels);
        }
        if (in_array($status, ['ready', 'failed'], true)) {
            $this->executionRatio('export', $status === 'failed');
        }
        if ($status === 'ready') {
            $this->storageRatio($reportCode, $format, false);
        }
    }

    public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void
    {
        $this->assertExportDimensions($reportCode, $format, $status);
        $this->metric(
            'reports_export_duration_seconds',
            $this->nonNegative($seconds),
            [
                'report_code' => $reportCode,
                'format' => $format,
                'status' => $status,
                'duration_bucket' => $this->durationBucket($seconds),
            ],
        );
        $this->durationAlert("export:{$reportCode}:{$format}:{$status}", $seconds, [
            'report_code' => $reportCode,
            'format' => $format,
            'status' => $status,
            'duration_seconds' => $seconds,
            'regression_ratio_threshold' => $this->alertThresholds['duration_regression_ratio'] ?? null,
        ]);
    }

    public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void
    {
        $this->assertReportCode($reportCode);
        $this->assertOneOf($format, self::FORMATS, 'report_telemetry_format_invalid');
        if ($rows < 0 || $bytes < 0) {
            throw new InvalidArgumentException('report_telemetry_artifact_invalid');
        }
        $labels = ['report_code' => $reportCode, 'format' => $format];
        $this->metric('reports_export_bytes', $bytes, $labels + ['size_bucket' => $this->sizeBucket($bytes)]);
        $this->metric('reports_export_rows', $rows, $labels + ['size_bucket' => $this->rowBucket($rows)]);
    }

    public function multipartAbort(string $reportCode, string $format): void
    {
        $this->assertReportCode($reportCode);
        $this->assertOneOf($format, self::FORMATS, 'report_telemetry_format_invalid');
        $this->metric(
            'reports_export_multipart_abort_total',
            1,
            ['report_code' => $reportCode, 'format' => $format],
        );
        $this->storageRatio($reportCode, $format, true);
    }

    public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void
    {
        $this->assertOneOf($intentType, self::INTENT_TYPES, 'report_telemetry_intent_invalid');
        $this->assertOneOf($topic, self::TOPICS, 'report_telemetry_topic_invalid');
        $this->assertOneOf($outcome, self::OUTCOMES, 'report_telemetry_outcome_invalid');
        $age = $this->nonNegative($ageSeconds);
        $labels = [
            'intent_type' => $intentType,
            'topic' => $topic,
            'outcome' => $outcome,
            'queue_class' => 'reports',
        ];
        $this->metric('reports_dispatch_intent_total', 1, $labels);
        $this->metric(
            'reports_dispatch_oldest_pending_seconds',
            $age,
            $labels + ['age_bucket' => $this->durationBucket($age)],
        );
        if (in_array($outcome, ['retry', 'failed'], true)) {
            $this->metric('reports_dispatch_publish_failed_total', 1, $labels);
        }
        $dispatchThreshold = $this->alertThresholds['dispatch_failure_ratio'] ?? null;
        if (
            is_float($dispatchThreshold)
            && $this->alerts->ratioExceeded(
                "dispatch:{$intentType}:{$topic}",
                in_array($outcome, ['retry', 'failed', 'dead_letter'], true),
                $dispatchThreshold,
            )
        ) {
            $this->alertSignal('dispatch_failure', $labels + [
                'ratio_threshold' => $dispatchThreshold,
            ], true);
        }
        if ($outcome === 'reclaimed') {
            $this->metric('reports_dispatch_lease_reclaimed_total', 1, $labels);
            $this->reclaimAlert("dispatch:{$intentType}:{$topic}", $labels);
        }
        if ($outcome === 'dead_letter') {
            $this->metric('reports_dispatch_dead_letter_total', 1, $labels);
            $this->alertSignal('dispatch_dead_letter', $labels, true);
        }
        $oldestPendingThreshold = $this->alertThresholds['oldest_pending_seconds'] ?? null;
        if (is_int($oldestPendingThreshold) && $age >= $oldestPendingThreshold) {
            $this->alertSignal('oldest_pending', $labels + [
                'age_seconds' => $age,
                'age_threshold_seconds' => $oldestPendingThreshold,
            ], true);
        }
    }

    public function executionAttempt(string $intentType, string $errorCode): void
    {
        $this->assertOneOf($intentType, ['run', 'export'], 'report_telemetry_intent_invalid');
        if (ReportErrorCode::tryFrom($errorCode) === null) {
            throw new InvalidArgumentException('report_telemetry_error_code_invalid');
        }
        $this->metric(
            'reports_execution_attempt_failed_total',
            1,
            ['intent_type' => $intentType, 'error_code' => $errorCode, 'queue_class' => 'reports'],
        );
        $this->executionRatio($intentType, true, $errorCode);
    }

    public function executionLeaseReclaimed(string $intentType): void
    {
        $this->assertOneOf($intentType, ['run', 'export'], 'report_telemetry_intent_invalid');
        $this->metric(
            'reports_execution_lease_reclaimed_total',
            1,
            ['intent_type' => $intentType, 'queue_class' => 'reports'],
        );
        $this->reclaimAlert("execution:{$intentType}", [
            'intent_type' => $intentType,
            'queue_class' => 'reports',
        ]);
    }

    public function auditDeliveryFailure(string $errorCode, string $outcome): void
    {
        if (ReportErrorCode::tryFrom($errorCode) === null) {
            throw new InvalidArgumentException('report_telemetry_error_code_invalid');
        }
        $this->assertOneOf($outcome, ['retry', 'dead_letter'], 'report_telemetry_outcome_invalid');
        $this->metric(
            'reports_audit_transition_failed_total',
            1,
            ['error_code' => $errorCode, 'outcome' => $outcome, 'queue_class' => 'reports'],
        );
        if ($outcome === 'dead_letter') {
            $this->alertSignal('audit_dead_letter', [
                'error_code' => $errorCode,
                'outcome' => $outcome,
                'queue_class' => 'reports',
                'count_threshold' => $this->alertThresholds['audit_dead_letters'] ?? null,
            ], true);
        }
    }

    private function metric(string $family, int|float $value, array $labels): void
    {
        $this->logger->info('reports.metric', [
            'family' => $family,
            'value' => $value,
            'labels' => $labels,
        ]);
    }

    private function alertSignal(string $signal, array $context, bool $critical = false): void
    {
        if ($this->alertThresholds === []) {
            return;
        }

        $context = ['signal' => $signal] + $context;
        if ($critical) {
            $this->logger->critical('reports.alert', $context);

            return;
        }

        $this->logger->info('reports.alert_input', $context);
    }

    private function reclaimAlert(string $key, array $context): void
    {
        $threshold = $this->alertThresholds['lease_reclaims'] ?? null;
        if (
            is_int($threshold)
            && $this->alerts->countReached($key, $threshold)
        ) {
            $this->alertSignal('lease_reclaims', $context + [
                'count_threshold' => $threshold,
            ], true);
        }
    }

    private function executionRatio(
        string $intentType,
        bool $failed,
        ?string $errorCode = null,
    ): void {
        $threshold = $this->alertThresholds['execution_error_ratio'] ?? null;
        if (
            is_float($threshold)
            && $this->alerts->ratioExceeded(
                "execution:{$intentType}",
                $failed,
                $threshold,
            )
        ) {
            $this->alertSignal('execution_error_ratio', [
                'intent_type' => $intentType,
                'error_code' => $errorCode,
                'queue_class' => 'reports',
                'ratio_threshold' => $threshold,
            ], true);
        }
    }

    private function durationAlert(
        string $key,
        float $seconds,
        array $context,
    ): void {
        $threshold = $this->alertThresholds['duration_regression_ratio'] ?? null;
        if (
            is_float($threshold)
            && $this->alerts->durationRegressed($key, $seconds, $threshold)
        ) {
            $this->alertSignal('duration_regression', $context + [
                'duration_seconds' => $seconds,
                'regression_ratio_threshold' => $threshold,
            ], true);
        }
    }

    private function storageRatio(
        string $reportCode,
        string $format,
        bool $aborted,
    ): void {
        $threshold = $this->alertThresholds['storage_abort_ratio'] ?? null;
        if (
            is_float($threshold)
            && $this->alerts->ratioExceeded(
                "storage:{$reportCode}:{$format}",
                $aborted,
                $threshold,
            )
        ) {
            $this->alertSignal('storage_abort_ratio', [
                'report_code' => $reportCode,
                'format' => $format,
                'ratio_threshold' => $threshold,
            ], true);
        }
    }

    private function assertExportDimensions(string $reportCode, string $format, string $status): void
    {
        $this->assertReportCode($reportCode);
        $this->assertOneOf($format, self::FORMATS, 'report_telemetry_format_invalid');
        $this->assertOneOf($status, self::EXPORT_STATUSES, 'report_telemetry_export_status_invalid');
    }

    private function assertReportCode(string $reportCode): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1) {
            throw new InvalidArgumentException('report_telemetry_report_code_invalid');
        }
    }

    private function assertOneOf(string $value, array $allowed, string $error): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }
    }

    private function nonNegative(float $value): float
    {
        if (! is_finite($value) || $value < 0) {
            throw new InvalidArgumentException('report_telemetry_value_invalid');
        }

        return $value;
    }

    private function durationBucket(float $seconds): string
    {
        return match (true) {
            $seconds <= 1 => 'le_1',
            $seconds <= 5 => 'le_5',
            $seconds <= 30 => 'le_30',
            $seconds <= 120 => 'le_120',
            $seconds <= 600 => 'le_600',
            default => 'gt_600',
        };
    }

    private function sizeBucket(int $bytes): string
    {
        return match (true) {
            $bytes <= 1_048_576 => 'le_1m',
            $bytes <= 10_485_760 => 'le_10m',
            $bytes <= 104_857_600 => 'le_100m',
            default => 'gt_100m',
        };
    }

    private function rowBucket(int $rows): string
    {
        return match (true) {
            $rows <= 1_000 => 'le_1k',
            $rows <= 10_000 => 'le_10k',
            $rows <= 100_000 => 'le_100k',
            default => 'gt_100k',
        };
    }
}
