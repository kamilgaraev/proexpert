<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityClock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityFrozenCaptureWriter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityPolicySource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureReceipt;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotService;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacitySnapshotServiceTest extends TestCase
{
    #[Test]
    public function streams_deterministic_cohorts_in_bounded_chunks_with_cursor_continuity(): void
    {
        $keys = [];
        for ($month = 1; $month <= 125; $month++) {
            $date = (new DateTimeImmutable('2026-08-01'))->modify('+'.($month - 1).' months');
            $keys[] = new WorkforceCapacityCohortKey(
                7,
                $date->format('Y-m-15'),
                $date->format('Y-m-01'),
                11,
                101,
            );
        }

        $source = new RecordingCapacitySource($keys, $this->source());
        $store = new RecordingCapacityStore;
        $service = new WorkforceCapacitySnapshotService(
            $source,
            new FixedCapacityPolicySource,
            $store,
            new FixedCapacityClock,
            40,
        );

        $result = $service->capture(new WorkforceCapacityCaptureCommand(
            mutationId: 'staff-unit:11:revision-2',
            organizationId: 7,
            sourceType: 'staff_unit',
            oldState: ['id' => 11, 'valid_from' => '2026-08-01'],
            newState: ['id' => 11, 'valid_from' => '2026-08-01'],
            captureKind: 'scheduled_close',
            actorUserId: null,
            serviceActor: 'workforce-scheduler',
        ));

        self::assertSame(125, $result->snapshotCount);
        self::assertSame(4, $result->chunkCount);
        self::assertSame([40, 40, 40, 5], $source->batchSizes);
        self::assertSame([40, 40, 40, 5], $store->batchSizes);
        self::assertNull($store->priorCursors[0]);
        self::assertSame($store->cursors[0], $store->priorCursors[1]);
        self::assertSame($store->cursors[1], $store->priorCursors[2]);
        self::assertSame($store->cursors[2], $store->priorCursors[3]);
        self::assertSame($store->cursors[3], $result->cursor);
        self::assertSame([['staff-unit:11:revision-2', 7, $result->cursor, 125, 4]], $store->completed);
    }

    #[Test]
    public function persistence_failure_is_not_swallowed_so_owner_transaction_can_roll_back(): void
    {
        $key = new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, 101);
        $service = new WorkforceCapacitySnapshotService(
            new RecordingCapacitySource([$key], $this->source()),
            new FixedCapacityPolicySource,
            new FailingCapacityStore,
            new FixedCapacityClock,
            40,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('capacity_store_failed');

        $service->capture(new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:revision-2',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: ['id' => 31],
            newState: ['id' => 31],
            captureKind: 'scheduled_close',
            actorUserId: null,
            serviceActor: 'workforce-scheduler',
        ));
    }

    #[Test]
    public function owner_change_is_always_frozen_without_materializing_even_one_sync_cohort(): void
    {
        $keys = [new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null)];
        $source = new RecordingCapacitySource($keys, $this->source());
        $store = new RecordingCapacityStore;
        $dispatcher = new RecordingSnapshotDeferredDispatcher;
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:deferred-limit',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: null,
            newState: ['id' => 31, 'organization_id' => 7, 'staff_unit_id' => 11],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );
        $writer = new RecordingFrozenCaptureWriter;
        $service = new WorkforceCapacitySnapshotService(
            $source,
            new FixedCapacityPolicySource,
            $store,
            new FixedCapacityClock,
            40,
            $writer,
            $dispatcher,
        );

        $result = $service->capture($command);

        self::assertSame(0, $result->snapshotCount);
        self::assertSame([], $source->batchSizes);
        self::assertSame([], $store->batchSizes);
        self::assertSame([$command], $writer->commands);
        self::assertSame([91], $dispatcher->requestIds);
    }

    private function source(): array
    {
        return [
            'staff_unit' => [
                'id' => 11,
                'organization_id' => 7,
                'department_id' => 2,
                'position_id' => 3,
                'headcount' => '2.00',
                'rate' => '1.0000',
                'valid_from' => '2026-08-01',
                'valid_to' => null,
                'is_active' => true,
                'deleted_at' => null,
            ],
            'assignments' => [],
            'schedules' => [],
            'schedule_days' => [],
            'absences' => [],
            'business_trips' => [],
            'employee_lifecycle' => [],
            'gaps' => [],
        ];
    }
}

final class RecordingCapacitySource implements WorkforceCapacityCurrentSource
{
    public array $batchSizes = [];

    public function __construct(private array $keys, private array $source) {}

    public function affectedCohorts(WorkforceCapacityCaptureCommand $command, string $asOfDate): iterable
    {
        return $this->keys;
    }

    public function readBatch(WorkforceCapacityCaptureCommand $command, array $keys): array
    {
        $this->batchSizes[] = count($keys);
        $result = [];
        foreach ($keys as $key) {
            $source = $this->source;
            $source['staff_unit']['valid_from'] = $key->monthStart;
            $result[$key->identity()] = $source;
        }

        return $result;
    }
}

final class FixedCapacityPolicySource implements WorkforceCapacityPolicySource
{
    public function forOrganization(int $organizationId): WorkforceCapacityPolicyDefinition
    {
        return WorkforceCapacityPolicyDefinition::v1('Europe/Moscow');
    }
}

final class FixedCapacityClock implements WorkforceCapacityClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-15T09:00:00+00:00');
    }
}

final class RecordingCapacityStore implements WorkforceCapacitySnapshotStore
{
    public array $batchSizes = [];

    public array $priorCursors = [];

    public array $cursors = [];

    public array $completed = [];

    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void {
        $this->batchSizes[] = count($snapshots);
        $this->priorCursors[] = $priorCursor;
        $this->cursors[] = $cursor;
    }

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void {
        $this->completed[] = [$mutationId, $organizationId, $cursor, $snapshotCount, $chunkCount];
    }
}

final class FailingCapacityStore implements WorkforceCapacitySnapshotStore
{
    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void {
        throw new LogicException('capacity_store_failed');
    }

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void {}
}

final class RecordingFrozenCaptureWriter implements WorkforceCapacityFrozenCaptureWriter
{
    public array $commands = [];

    public function freezeAndEnqueue(
        WorkforceCapacityCaptureCommand $command,
        WorkforceCapacityPolicyDefinition $policy,
        DateTimeImmutable $capturedAt,
        string $businessDate,
    ): WorkforceCapacityFrozenCaptureReceipt {
        $this->commands[] = $command;

        return new WorkforceCapacityFrozenCaptureReceipt(91, true);
    }
}

final class RecordingSnapshotDeferredDispatcher implements WorkforceCapacityDeferredCaptureDispatcher
{
    public array $requestIds = [];

    public function dispatchAfterCommit(int $captureRequestId): void
    {
        $this->requestIds[] = $captureRequestId;
    }
}
