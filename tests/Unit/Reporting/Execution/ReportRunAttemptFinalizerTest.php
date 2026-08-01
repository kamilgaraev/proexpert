<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Listeners\FinalizeFailedReportRunAttempt;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\FakeReportExecutionClock;

final class ReportRunAttemptFinalizerTest extends TestCase
{
    public function test_it_maps_catalogued_and_unknown_failures_to_safe_codes(): void
    {
        $store = new class implements ReportRunAttemptLifecycleStore
        {
            public array $calls = [];

            public function claimOrRenew(string $runId, string $envelopeUuid, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): bool
            {
                return false;
            }

            public function failLeased(string $runId, string $envelopeUuid, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): bool
            {
                $this->calls[] = [$runId, $envelopeUuid, $errorCode];

                return true;
            }
        };
        $finalizer = new ReportRunAttemptFinalizer($store);
        $uuid = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';
        $at = new DateTimeImmutable('2026-07-26T10:00:00Z');

        self::assertTrue($finalizer->finalize(
            '01J00000000000000000000000',
            $uuid,
            ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE),
            $at,
        ));
        self::assertTrue($finalizer->finalize('01J00000000000000000000000', $uuid, new RuntimeException('secret'), $at));
        self::assertSame(
            [ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, ReportErrorCode::REPORT_INTERNAL_ERROR],
            array_column($store->calls, 2),
        );
    }

    public function test_failed_event_listener_accepts_only_the_exact_queue_job_and_uuid(): void
    {
        $store = new class implements ReportRunAttemptLifecycleStore
        {
            public array $calls = [];

            public function claimOrRenew(string $runId, string $envelopeUuid, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): bool
            {
                return false;
            }

            public function failLeased(string $runId, string $envelopeUuid, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): bool
            {
                $this->calls[] = [$runId, $envelopeUuid, $errorCode];

                return true;
            }
        };
        $listener = new FinalizeFailedReportRunAttempt(
            new ReportRunAttemptFinalizer($store),
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->telemetry(),
        );
        $job = $this->createMock(Job::class);
        $job->method('uuid')->willReturn('0195e44b-a9e7-7f12-a8af-51f2d284d3ef');
        $job->method('getQueue')->willReturn('reports');
        $job->method('resolveName')->willReturn(MaterializeReportRunJob::class);
        $job->method('payload')->willReturn([
            'data' => ['command' => serialize(new MaterializeReportRunJob('01J00000000000000000000000'))],
        ]);

        $listener(new JobFailed('redis_reports', $job, new RuntimeException('secret')));
        $listener(new JobFailed('redis', $job, new RuntimeException('secret')));
        $malformed = $this->createMock(Job::class);
        $malformed->method('uuid')->willReturn('not-a-uuid');
        $malformed->method('getQueue')->willReturn('reports');
        $malformed->method('resolveName')->willReturn(MaterializeReportRunJob::class);
        $malformed->method('payload')->willReturn(['data' => ['command' => 'corrupt']]);
        $listener(new JobFailed('redis_reports', $malformed, new RuntimeException('secret')));
        $listener(new JobFailed('redis_reports', $job, null));

        self::assertCount(1, $store->calls);
        self::assertSame('01J00000000000000000000000', $store->calls[0][0]);
        self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $store->calls[0][2]);
    }

    private function telemetry(): ReportExecutionTelemetry
    {
        return new class implements ReportExecutionTelemetry
        {
            public function runTransition(string $reportCode, string $status): void {}

            public function runDuration(string $reportCode, string $status, float $seconds): void {}

            public function exportTransition(string $reportCode, string $format, string $status): void {}

            public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void {}

            public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void {}

            public function multipartAbort(string $reportCode, string $format): void {}

            public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void {}

            public function executionAttempt(string $intentType, string $errorCode): void {}

            public function executionLeaseReclaimed(string $intentType): void {}

            public function auditDeliveryFailure(string $errorCode, string $outcome): void {}
        };
    }
}
