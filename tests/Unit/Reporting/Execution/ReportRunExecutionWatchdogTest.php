<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExpiredExecutionLease;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExecutionWatchdog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportRunExecutionWatchdogTest extends TestCase
{
    public function test_it_counts_requeued_skipped_and_failed_candidates(): void
    {
        $at = new DateTimeImmutable('2026-07-26T10:00:00Z');
        $leases = array_map(
            static fn (int $i): ReportExpiredExecutionLease => new ReportExpiredExecutionLease(
                'run',
                '01J0000000000000000000000'.$i,
                7,
                sprintf('0195e44b-a9e7-7f12-a8af-51f2d284d3e%d', $i),
                $at->modify('-1 second'),
            ),
            [1, 2, 3],
        );
        $store = new class($leases) implements ReportRunLeaseRecoveryStore
        {
            public function __construct(private array $leases) {}

            public function due(int $limit, DateTimeImmutable $occurredAt): array
            {
                return $this->leases;
            }

            public function requeue(ReportExpiredExecutionLease $lease, DateTimeImmutable $occurredAt): bool
            {
                return match (substr($lease->aggregateId, -1)) {
                    '1' => true,
                    '2' => false,
                    default => throw new RuntimeException('transport unavailable'),
                };
            }
        };
        $telemetry = $this->telemetry();

        $summary = (new ReportRunExecutionWatchdog($store, $telemetry))->reclaim(3, $at);

        self::assertSame([3, 1, 1, 1], [$summary->scanned, $summary->requeued, $summary->skipped, $summary->failed]);
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
