<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExpiredExecutionLease;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunLeaseRecoveryStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportRunLeaseRecoveryStoreContractTest extends TestCase
{
    public function test_recovery_port_and_closed_candidate_are_exact(): void
    {
        $lease = new ReportExpiredExecutionLease(
            'run',
            '01J00000000000000000000000',
            7,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            new DateTimeImmutable('2026-07-26T10:00:00.123456Z'),
        );
        self::assertSame(
            ['aggregateKind', 'aggregateId', 'organizationId', 'expectedLeaseToken', 'leaseExpiresAt'],
            array_keys(get_object_vars($lease)),
        );
        self::assertSame('export', (new ReportExpiredExecutionLease(
            'export',
            '01J00000000000000000000001',
            7,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3e0',
            new DateTimeImmutable('2026-07-26T10:00:00.123456Z'),
        ))->aggregateKind);

        $reflection = new ReflectionClass(ReportRunLeaseRecoveryStore::class);
        self::assertSame(['due', 'requeue'], array_map(
            static fn ($method): string => $method->getName(),
            $reflection->getMethods(),
        ));
    }

    public function test_run_recovery_rejects_export_candidate_before_persistence_access(): void
    {
        $intents = new class implements ReportDispatchIntentStore
        {
            public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void
            {
                throw new \LogicException('Dispatch persistence must not be touched.');
            }

            public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

            public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
            {
                return [];
            }

            public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void {}

            public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}

            public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
            {
                return 0;
            }
        };

        self::assertFalse((new EloquentReportRunLeaseRecoveryStore($intents))->requeue(
            new ReportExpiredExecutionLease(
                'export',
                '01J00000000000000000000001',
                7,
                '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
                new DateTimeImmutable('2026-07-26T10:00:00Z'),
            ),
            new DateTimeImmutable('2026-07-26T10:00:01Z'),
        ));
    }
}
