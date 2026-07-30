<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Telemetry;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php';

use App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\LaravelReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\ReportExecutionAlertWindow;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ReportExecutionTelemetryTest extends TestCase
{
    public function test_exact_metric_families_use_only_bounded_labels(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $telemetry = new LaravelReportExecutionTelemetry(
            $logger,
            [],
            new ReportExecutionAlertWindow,
        );

        $telemetry->runTransition('cash_flow', 'ready');
        $telemetry->runDuration('cash_flow', 'ready', 1.25);
        $telemetry->exportTransition('cash_flow', 'xlsx', 'ready');
        $telemetry->exportDuration('cash_flow', 'xlsx', 'ready', 2.5);
        $telemetry->exportArtifact('cash_flow', 'xlsx', 11, 4096);
        $telemetry->multipartAbort('cash_flow', 'xlsx');
        $telemetry->dispatchIntent('run', 'materialize_run', 'published', 61.0);
        $telemetry->executionAttempt('run', 'REPORT_INTERNAL_ERROR');
        $telemetry->executionLeaseReclaimed('export');
        $telemetry->auditDeliveryFailure('REPORT_DEPENDENCY_FAILED', 'retry');

        $families = [];
        foreach ($logger->records as [, $message, $context]) {
            self::assertSame('reports.metric', $message);
            self::assertSame([], array_intersect(
                ['actor_id', 'organization_id', 'run_id', 'export_id', 'intent_id', 'event_key', 'lease_token', 'object_path', 'filter', 'cursor', 'signature', 'key_id', 'exception'],
                array_keys($context['labels']),
            ));
            $families[] = $context['family'];
        }

        self::assertSame([
            'reports_run_total',
            'reports_run_duration_seconds',
            'reports_export_total',
            'reports_export_duration_seconds',
            'reports_export_bytes',
            'reports_export_rows',
            'reports_export_multipart_abort_total',
            'reports_dispatch_intent_total',
            'reports_dispatch_oldest_pending_seconds',
            'reports_execution_attempt_failed_total',
            'reports_execution_lease_reclaimed_total',
            'reports_audit_transition_failed_total',
        ], $families);
    }

    public function test_invalid_unbounded_dimensions_fail_closed(): void
    {
        $logger = new class extends AbstractLogger
        {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        $telemetry = new LaravelReportExecutionTelemetry(
            $logger,
            [],
            new ReportExecutionAlertWindow,
        );

        $this->expectException(\InvalidArgumentException::class);
        $telemetry->runTransition('cash_flow', 'ready:01J123456789012345678901234');
    }

    public function test_failure_reclaim_and_dead_letter_families_are_emitted(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $telemetry = new LaravelReportExecutionTelemetry($logger, [
            'oldest_pending_seconds' => 300,
            'audit_dead_letters' => 0,
            'dispatch_failure_ratio' => 0.05,
            'lease_reclaims' => 3,
            'execution_error_ratio' => 0.05,
            'duration_regression_ratio' => 1.25,
            'storage_abort_ratio' => 0.01,
        ], new ReportExecutionAlertWindow);

        $telemetry->runTransition('cash_flow', 'failed');
        $telemetry->exportTransition('cash_flow', 'pdf', 'failed');
        $telemetry->dispatchIntent('run', 'materialize_run', 'failed', 301.0);
        $telemetry->dispatchIntent('export', 'generate_export', 'reclaimed', 10.0);
        $telemetry->dispatchIntent('run', 'materialize_run', 'dead_letter', 20.0);
        $telemetry->auditDeliveryFailure('REPORT_DEPENDENCY_FAILED', 'dead_letter');

        $metrics = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record[1] === 'reports.metric',
        ));
        $families = array_column(array_column($metrics, 2), 'family');
        foreach ([
            'reports_run_failed_total',
            'reports_export_failed_total',
            'reports_dispatch_publish_failed_total',
            'reports_dispatch_lease_reclaimed_total',
            'reports_dispatch_dead_letter_total',
            'reports_audit_transition_failed_total',
        ] as $family) {
            self::assertContains($family, $families);
        }

        $alerts = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record[1] === 'reports.alert',
        ));
        self::assertNotEmpty($alerts);
        self::assertContains('oldest_pending', array_column(array_column($alerts, 2), 'signal'));
        self::assertContains('audit_dead_letter', array_column(array_column($alerts, 2), 'signal'));
    }

    public function test_sustained_thresholds_emit_fail_hard_alerts(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $telemetry = new LaravelReportExecutionTelemetry($logger, [
            'oldest_pending_seconds' => 300,
            'audit_dead_letters' => 0,
            'dispatch_failure_ratio' => 0.05,
            'lease_reclaims' => 3,
            'execution_error_ratio' => 0.05,
            'duration_regression_ratio' => 1.25,
            'storage_abort_ratio' => 0.01,
        ], new ReportExecutionAlertWindow);

        for ($index = 0; $index < 20; $index++) {
            $telemetry->dispatchIntent('run', 'materialize_run', 'retry', 1.0);
            $telemetry->executionAttempt('run', 'REPORT_INTERNAL_ERROR');
            $telemetry->exportTransition('cash_flow', 'xlsx', 'ready');
            $telemetry->multipartAbort('cash_flow', 'xlsx');
        }
        for ($index = 0; $index < 5; $index++) {
            $telemetry->runDuration('cash_flow', 'ready', 1.0);
        }
        $telemetry->runDuration('cash_flow', 'ready', 2.0);
        for ($index = 0; $index < 3; $index++) {
            $telemetry->executionLeaseReclaimed('run');
        }

        $signals = array_column(array_column(array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record[1] === 'reports.alert',
        )), 2), 'signal');

        foreach ([
            'dispatch_failure',
            'execution_error_ratio',
            'storage_abort_ratio',
            'duration_regression',
            'lease_reclaims',
        ] as $signal) {
            self::assertContains($signal, $signals);
        }
        self::assertSame(1, count(array_filter($signals, static fn (string $signal): bool => $signal === 'dispatch_failure')));
        self::assertSame(1, count(array_filter($signals, static fn (string $signal): bool => $signal === 'execution_error_ratio')));
        self::assertSame(1, count(array_filter($signals, static fn (string $signal): bool => $signal === 'storage_abort_ratio')));
    }
}
