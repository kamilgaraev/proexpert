<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportLeaseRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExpiredExecutionLease;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportExportLeaseRecoveryStoreContractTest extends TestCase
{
    public function test_recovery_port_and_candidate_are_exact(): void
    {
        $lease = new ReportExpiredExecutionLease(
            'export',
            '01J00000000000000000000000',
            7,
            '0195e44b-a9e7-7f12-a8af-51f2d284d3ef',
            new DateTimeImmutable('2026-07-26T10:00:00.123456Z'),
        );

        self::assertSame('export', $lease->aggregateKind);
        self::assertSame(['due', 'requeue'], array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass(ReportExportLeaseRecoveryStore::class))->getMethods(),
        ));
    }

    public function test_attempt_lifecycle_has_only_the_two_authority_free_methods(): void
    {
        $reflection = new ReflectionClass(ReportExportAttemptLifecycleStore::class);

        self::assertSame(['claimOrRenew', 'failLeased'], array_map(
            static fn ($method): string => $method->getName(),
            $reflection->getMethods(),
        ));
        self::assertSame(
            ReportErrorCode::class,
            $reflection->getMethod('failLeased')->getParameters()[2]->getType()?->getName(),
        );
    }
}
