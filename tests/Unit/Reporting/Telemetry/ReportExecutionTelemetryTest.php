<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Telemetry;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Infrastructure/Telemetry/LaravelReportExecutionTelemetry.php';

use App\BusinessModules\Core\Reporting\Infrastructure\Telemetry\LaravelReportExecutionTelemetry;
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
        $telemetry = new LaravelReportExecutionTelemetry($logger);

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
        $telemetry = new LaravelReportExecutionTelemetry($logger);

        $this->expectException(\InvalidArgumentException::class);
        $telemetry->runTransition('cash_flow', 'ready:01J123456789012345678901234');
    }
}
