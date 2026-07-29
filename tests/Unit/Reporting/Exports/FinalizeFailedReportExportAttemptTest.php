<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Listeners\FinalizeFailedReportExportAttempt;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\FakeReportExecutionClock;

final class FinalizeFailedReportExportAttemptTest extends TestCase
{
    public function test_listener_accepts_only_exact_reports_queue_job_and_id_only_payload(): void
    {
        $store = new class implements ReportExportAttemptLifecycleStore
        {
            public array $calls = [];

            public function claimOrRenew(
                string $exportId,
                string $envelopeUuid,
                DateTimeImmutable $leaseExpiresAt,
                DateTimeImmutable $occurredAt,
            ): bool {
                return false;
            }

            public function failLeased(
                string $exportId,
                string $envelopeUuid,
                ReportErrorCode $errorCode,
                DateTimeImmutable $occurredAt,
            ): bool {
                $this->calls[] = [$exportId, $envelopeUuid, $errorCode];

                return true;
            }
        };
        $listener = new FinalizeFailedReportExportAttempt(
            new ReportExportAttemptFinalizer($store),
            new FakeReportExecutionClock(new DateTimeImmutable('2026-07-26T10:00:00Z')),
            $this->telemetry(),
        );
        $job = $this->queueJob(
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            GenerateReportExportJob::class,
            serialize(new GenerateReportExportJob('01J00000000000000000000000')),
        );

        $listener(new JobFailed('redis_reports', $job, new RuntimeException('secret')));
        $listener(new JobFailed('redis', $job, new RuntimeException('secret')));
        $listener(new JobFailed(
            'redis_reports',
            $this->queueJob('invalid', GenerateReportExportJob::class, 'corrupt'),
            new RuntimeException('secret'),
        ));

        self::assertCount(1, $store->calls);
        self::assertSame('01J00000000000000000000000', $store->calls[0][0]);
        self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $store->calls[0][2]);
    }

    private function queueJob(string $uuid, string $name, string $command): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('uuid')->willReturn($uuid);
        $job->method('getQueue')->willReturn('reports');
        $job->method('resolveName')->willReturn($name);
        $job->method('payload')->willReturn(['data' => ['command' => $command]]);

        return $job;
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
