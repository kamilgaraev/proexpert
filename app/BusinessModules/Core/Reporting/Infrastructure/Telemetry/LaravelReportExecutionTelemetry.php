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
        private array $alertThresholds = [],
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
    }

    public function exportTransition(string $reportCode, string $format, string $status): void
    {
        $this->assertExportDimensions($reportCode, $format, $status);
        $labels = ['report_code' => $reportCode, 'format' => $format, 'status' => $status];
        $this->metric('reports_export_total', 1, $labels);
        if ($status === 'failed') {
            $this->metric('reports_export_failed_total', 1, $labels);
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
        if ($outcome === 'reclaimed') {
            $this->metric('reports_dispatch_lease_reclaimed_total', 1, $labels);
        }
        if ($outcome === 'dead_letter') {
            $this->metric('reports_dispatch_dead_letter_total', 1, $labels);
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
    }

    public function executionLeaseReclaimed(string $intentType): void
    {
        $this->assertOneOf($intentType, ['run', 'export'], 'report_telemetry_intent_invalid');
        $this->metric(
            'reports_execution_lease_reclaimed_total',
            1,
            ['intent_type' => $intentType, 'queue_class' => 'reports'],
        );
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
    }

    private function metric(string $family, int|float $value, array $labels): void
    {
        $this->logger->info('reports.metric', [
            'family' => $family,
            'value' => $value,
            'labels' => $labels,
        ]);
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
