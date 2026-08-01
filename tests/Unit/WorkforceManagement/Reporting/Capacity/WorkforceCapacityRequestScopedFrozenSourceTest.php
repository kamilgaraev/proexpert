<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityClock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityPolicySource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureRequestState;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenSourceProjection;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\RequestScopedWorkforceCapacityDeferredSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\RequestScopedWorkforceCapacityFrozenCaptureWriter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\RequestScopedWorkforceCapacityLifecycleCaptureCoordinator;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityRequestScopedFrozenSourceTest extends TestCase
{
    #[Test]
    public function writer_streams_every_compact_range_without_a_product_row_cap(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->materializedRangeCount = 600;
        $writer = new RequestScopedWorkforceCapacityFrozenCaptureWriter($gateway);

        $receipt = $writer->freezeAndEnqueue(
            $this->command(),
            WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            '2026-08-15',
        );

        self::assertSame(91, $receipt->requestId);
        self::assertTrue($receipt->dispatchRequired);
        self::assertSame(600, $gateway->rangeCount);
        self::assertSame(2400, $gateway->sourceRowCount);
        self::assertTrue($gateway->sealed);
        self::assertSame(1, $gateway->materializeRangeCalls);
    }

    #[Test]
    public function writer_completes_a_zero_affected_capture_without_materialization_or_dispatch(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->materializedRangeCount = 0;

        $receipt = (new RequestScopedWorkforceCapacityFrozenCaptureWriter($gateway))
            ->freezeAndEnqueue(
                $this->command(),
                WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
                new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
                '2026-08-15',
            );

        self::assertSame(91, $receipt->requestId);
        self::assertFalse($receipt->dispatchRequired);
        self::assertSame(0, $gateway->materializeCalls);
        self::assertTrue($gateway->sealed);
        self::assertSame(0, $gateway->sealedRangeCount);
    }

    #[Test]
    public function writer_reuses_an_exact_in_flight_request_without_rebuilding_frozen_rows(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->createdRequestState = new WorkforceCapacityFrozenCaptureRequestState(91, false, true);

        $receipt = (new RequestScopedWorkforceCapacityFrozenCaptureWriter($gateway))
            ->freezeAndEnqueue(
                $this->command(),
                WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
                new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
                '2026-08-15',
            );

        self::assertTrue($receipt->dispatchRequired);
        self::assertSame(0, $gateway->materializeRangeCalls);
        self::assertSame(0, $gateway->materializeCalls);
    }

    #[Test]
    public function writer_requires_the_owner_database_transaction(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->insideOwnerTransaction = false;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workforce_capacity_owner_transaction_required');

        (new RequestScopedWorkforceCapacityFrozenCaptureWriter($gateway))
            ->freezeAndEnqueue(
                $this->command(),
                WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
                new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
                '2026-08-15',
            );
    }

    #[Test]
    public function frozen_pins_reject_restricted_fields_before_persistence(): void
    {
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:restricted',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: null,
            newState: [
                'id' => 31,
                'organization_id' => 7,
                'staff_unit_id' => 11,
                'email' => 'private@example.test',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_restricted_source_field');

        new WorkforceCapacityFrozenCapturePins(
            $command,
            WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            '2026-08-15',
        );
    }

    #[Test]
    public function frozen_pins_capture_the_exact_source_and_formula_versions(): void
    {
        $pins = new WorkforceCapacityFrozenCapturePins(
            $this->command(),
            WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            '2026-08-15',
        );

        self::assertSame('workforce-capacity-source.v1', $pins->sourceSchemaVersion);
        self::assertSame('workforce-capacity-formula.v1', $pins->formulaVersion);
        self::assertStringContainsString('"formula_version":"workforce-capacity-formula.v1"', $pins->commandCanonical());
        self::assertStringContainsString('"source_schema_version":"workforce-capacity-source.v1"', $pins->commandCanonical());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_frozen_version_invalid');
        new WorkforceCapacityFrozenCapturePins(
            $this->command(),
            WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            '2026-08-15',
            sourceSchemaVersion: 'workforce-capacity-source.v2',
        );
    }

    #[Test]
    public function deferred_reader_builds_sources_only_from_request_scoped_projections(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $key = new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null);
        $gateway->nextKeys = [$key];
        $gateway->projections = [
            new WorkforceCapacityFrozenSourceProjection($key->identity(), 'staff_unit', [
                'id' => 11,
                'organization_id' => 7,
                'headcount' => '2.0000',
            ]),
            new WorkforceCapacityFrozenSourceProjection($key->identity(), 'assignment', [
                'id' => 31,
                'organization_id' => 7,
                'employee_id' => 41,
                'staff_unit_id' => 11,
                'project_id' => null,
            ]),
            new WorkforceCapacityFrozenSourceProjection($key->identity(), 'schedule', [
                'id' => 51,
                'organization_id' => 7,
                'hours_per_day' => '8.00',
                'week_pattern' => ['work_days' => [1, 2, 3, 4, 5]],
            ]),
        ];
        $reader = new RequestScopedWorkforceCapacityDeferredSourceReader($gateway);

        $keys = $reader->nextKeys(91, null, 65);
        $sources = $reader->readBatch(91, $keys);

        self::assertSame([$key], $keys);
        self::assertSame(11, $sources[$key->identity()]['staff_unit']['id']);
        self::assertSame([31], array_column($sources[$key->identity()]['assignments'], 'id'));
        self::assertSame([51], array_column($sources[$key->identity()]['schedules'], 'id'));
        self::assertSame([], $sources[$key->identity()]['schedule_days']);
        self::assertSame([], $sources[$key->identity()]['gaps']);
    }

    #[Test]
    public function deferred_reader_enforces_consumer_windows_without_silently_truncating(): void
    {
        $reader = new RequestScopedWorkforceCapacityDeferredSourceReader(new InMemoryRequestScopedFrozenSourceGateway);

        try {
            $reader->nextKeys(91, null, 66);
            self::fail('The cohort window must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('workforce_capacity_deferred_key_limit_invalid', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_deferred_source_batch_invalid');
        $reader->readBatch(91, array_fill(
            0,
            65,
            new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null),
        ));
    }

    #[Test]
    public function frozen_projection_rejects_non_iso_source_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_frozen_projection_type_invalid');

        new WorkforceCapacityFrozenSourceProjection(
            '7:2026-08-01:11:null',
            'assignment',
            ['id' => 31, 'valid_from' => '15.08.2026', 'rate' => '1.0000'],
        );
    }

    #[Test]
    public function frozen_projection_rejects_floating_point_source_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_frozen_projection_type_invalid');

        new WorkforceCapacityFrozenSourceProjection(
            '7:2026-08-01:11:null',
            'assignment',
            ['id' => 31, 'valid_from' => '2026-08-15', 'rate' => 1.0],
        );
    }

    #[Test]
    public function lifecycle_capture_stages_ranges_on_both_sides_of_the_owner_mutation_without_assignment_arrays(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->lifecycleRangeInsertions = [3, 2];
        $dispatcher = new RequestScopedRecordingDispatcher;
        $coordinator = new RequestScopedWorkforceCapacityLifecycleCaptureCoordinator(
            new FixedRequestScopedPolicySource,
            new FixedRequestScopedClock,
            $gateway,
            $dispatcher,
        );

        $draft = $coordinator->beginDismissal(7, 41, '2026-08-15');
        $coordinator->finishDismissal($draft);

        self::assertSame(91, $draft->requestId);
        self::assertSame([['request_id' => 91, 'organization_id' => 7, 'employee_id' => 41, 'date' => '2026-08-15'], ['request_id' => 91, 'organization_id' => 7, 'employee_id' => 41, 'date' => '2026-08-15']], $gateway->lifecycleStages);
        self::assertSame(5, $gateway->sealedRangeCount);
        self::assertSame([91], $dispatcher->requestIds);
        self::assertNull($gateway->createdPins?->command->oldState['assignments'] ?? null);
        self::assertNull($gateway->createdPins?->command->newState['assignments'] ?? null);
    }

    #[Test]
    public function lifecycle_capture_completes_without_a_job_when_the_employee_has_no_assignments(): void
    {
        $gateway = new InMemoryRequestScopedFrozenSourceGateway;
        $gateway->lifecycleRangeInsertions = [0, 0];
        $dispatcher = new RequestScopedRecordingDispatcher;
        $coordinator = new RequestScopedWorkforceCapacityLifecycleCaptureCoordinator(
            new FixedRequestScopedPolicySource,
            new FixedRequestScopedClock,
            $gateway,
            $dispatcher,
        );

        $coordinator->finishDismissal($coordinator->beginDismissal(7, 41, '2026-08-15'));

        self::assertTrue($gateway->sealed);
        self::assertSame(0, $gateway->sealedRangeCount);
        self::assertSame(0, $gateway->materializeCalls);
        self::assertSame([], $dispatcher->requestIds);
    }

    private function command(): WorkforceCapacityCaptureCommand
    {
        return new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:31:request-scoped',
            organizationId: 7,
            sourceType: 'assignment',
            oldState: null,
            newState: ['id' => 31, 'organization_id' => 7, 'staff_unit_id' => 11],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );
    }
}

final class InMemoryRequestScopedFrozenSourceGateway implements WorkforceCapacityRequestScopedFrozenSourceGateway
{
    public bool $insideOwnerTransaction = true;
    public int $rangeCount = 0;
    public int $sourceRowCount = 2400;
    public bool $sealed = false;
    public array $nextKeys = [];
    public array $projections = [];
    public ?WorkforceCapacityFrozenCapturePins $createdPins = null;
    public array $lifecycleRangeInsertions = [];
    public array $lifecycleStages = [];
    public int $sealedRangeCount = 0;
    public int $materializeCalls = 0;
    public int $materializeRangeCalls = 0;
    public int $materializedRangeCount = 1;
    public ?WorkforceCapacityFrozenCaptureRequestState $createdRequestState = null;

    public function isInsideOwnerTransaction(): bool
    {
        return $this->insideOwnerTransaction;
    }

    public function createRequest(WorkforceCapacityFrozenCapturePins $pins): WorkforceCapacityFrozenCaptureRequestState
    {
        $this->createdPins = $pins;

        return $this->createdRequestState ?? new WorkforceCapacityFrozenCaptureRequestState(91, true, false);
    }

    public function materializeRanges(WorkforceCapacityFrozenCapturePins $pins, int $captureRequestId): int
    {
        $this->materializeRangeCalls++;
        $this->rangeCount = $this->materializedRangeCount;

        return $this->materializedRangeCount;
    }

    public function materializeSourceRows(int $captureRequestId): int
    {
        $this->materializeCalls++;

        return $this->sourceRowCount;
    }

    public function stageLifecycleRanges(int $captureRequestId, int $organizationId, int $employeeId, string $dismissalDate): int
    {
        $this->lifecycleStages[] = [
            'request_id' => $captureRequestId,
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'date' => $dismissalDate,
        ];

        $inserted = array_shift($this->lifecycleRangeInsertions) ?? 0;
        $this->rangeCount += $inserted;

        return $inserted;
    }

    public function sealRequest(int $captureRequestId, int $rangeCount, int $sourceRowCount): bool
    {
        if ($this->rangeCount !== $rangeCount
            || ($rangeCount > 0 && $this->sourceRowCount !== $sourceRowCount)
            || ($rangeCount === 0 && $sourceRowCount !== 0)) {
            throw new LogicException('test_gateway_count_mismatch');
        }
        $this->sealed = true;
        $this->sealedRangeCount = $rangeCount;

        return $rangeCount > 0;
    }

    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array
    {
        return array_slice($this->nextKeys, 0, $limit);
    }

    public function sourceProjections(int $captureRequestId, array $keys): iterable
    {
        yield from $this->projections;
    }
}

final readonly class FixedRequestScopedPolicySource implements WorkforceCapacityPolicySource
{
    public function forOrganization(int $organizationId): WorkforceCapacityPolicyDefinition
    {
        return WorkforceCapacityPolicyDefinition::v1('Europe/Moscow');
    }
}

final readonly class FixedRequestScopedClock implements WorkforceCapacityClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-15T09:00:00+00:00');
    }
}

final class RequestScopedRecordingDispatcher implements WorkforceCapacityDeferredCaptureDispatcher
{
    public array $requestIds = [];

    public function dispatchAfterCommit(int $captureRequestId): void
    {
        $this->requestIds[] = $captureRequestId;
    }
}
