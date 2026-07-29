<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReportQueueRuntimeContractTest extends TestCase
{
    public function test_reports_queue_has_one_closed_runtime_with_safe_timeout_ordering(): void
    {
        $queue = require dirname(__DIR__, 3).'/config/queue.php';
        $horizon = require dirname(__DIR__, 3).'/config/horizon.php';
        $connection = $queue['connections']['redis_reports'] ?? null;

        self::assertIsArray($connection);
        self::assertSame([
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'reports',
            'job_timeout' => 900,
            'execution_lease_seconds' => 960,
            'retry_after' => 1200,
            'block_for' => null,
            'after_commit' => false,
        ], $connection);

        $jobTimeout = $connection['job_timeout'];
        $executionLeaseSeconds = $connection['execution_lease_seconds'];
        $redisRetryAfter = $connection['retry_after'];

        self::assertSame(900, $jobTimeout);
        self::assertSame(960, $executionLeaseSeconds);
        self::assertLessThan($executionLeaseSeconds, $jobTimeout);
        self::assertLessThan($redisRetryAfter, $executionLeaseSeconds);

        foreach (['production', 'local'] as $environment) {
            $supervisor = $horizon['environments'][$environment]['supervisor-reports'] ?? null;

            self::assertIsArray($supervisor);
            self::assertSame('redis_reports', $supervisor['connection'] ?? null);
            self::assertSame(['reports'], $supervisor['queue'] ?? null);
            self::assertSame($executionLeaseSeconds, $supervisor['timeout'] ?? null);
            self::assertSame(1, $supervisor['tries'] ?? null);
            self::assertSame(512, $supervisor['memory'] ?? null);
            self::assertGreaterThan($jobTimeout, $supervisor['timeout']);
            self::assertLessThan($redisRetryAfter, $supervisor['timeout']);
        }

        self::assertSame(120, $horizon['waits']['redis_reports:reports'] ?? null);
    }

    public function test_run_lease_renewal_compares_every_timestamp_with_microsecond_precision(): void
    {
        $record = new ReportRunRecord();
        $record->setRawAttributes([
            'execution_lease_expires_at' => new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            'execution_heartbeat_at' => new DateTimeImmutable('2026-07-26T09:55:00.111111Z'),
            'updated_at' => new DateTimeImmutable('2026-07-26T09:55:00.222222Z'),
        ]);
        $contract = new ReflectionMethod(EloquentReportRunStore::class, 'isMonotonicLeaseRenewal');
        $store = (new ReflectionClass(EloquentReportRunStore::class))->newInstanceWithoutConstructor();

        self::assertTrue($contract->invoke(
            $store,
            $record,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.222222Z'),
        ));
        self::assertTrue($contract->invoke(
            $store,
            $record,
            new DateTimeImmutable('2026-07-26T10:15:00.000000Z'),
            new DateTimeImmutable('2026-07-26T09:56:00.000000Z'),
        ));
        self::assertFalse($contract->invoke(
            $store,
            $record,
            new DateTimeImmutable('2026-07-26T10:00:00.899999Z'),
            new DateTimeImmutable('2026-07-26T09:56:00.000000Z'),
        ));
        $heartbeatOnlyRecord = new ReportRunRecord();
        $heartbeatOnlyRecord->setRawAttributes([
            'execution_lease_expires_at' => new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            'execution_heartbeat_at' => new DateTimeImmutable('2026-07-26T09:55:00.222222Z'),
            'updated_at' => new DateTimeImmutable('2026-07-26T09:55:00.111111Z'),
        ]);
        self::assertFalse($contract->invoke(
            $store,
            $heartbeatOnlyRecord,
            new DateTimeImmutable('2026-07-26T10:15:00.000000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.166666Z'),
        ));
        self::assertFalse($contract->invoke(
            $store,
            $record,
            new DateTimeImmutable('2026-07-26T10:15:00.000000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.222221Z'),
        ));
    }
}
