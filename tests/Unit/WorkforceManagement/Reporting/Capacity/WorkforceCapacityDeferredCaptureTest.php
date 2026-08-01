<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityDeferredCaptureClaim;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Jobs\MaterializeWorkforceCapacityCaptureJob;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityDeferredCaptureProcessor;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotBatchOrder;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotEvaluatorRegistry;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityDeferredCaptureTest extends TestCase
{
    #[Test]
    public function deferred_processor_materializes_one_chunk_and_redispatches_by_request_id(): void
    {
        $store = new InMemoryDeferredCaptureStore($this->pins());
        $source = new InMemoryDeferredSourceReader(129);
        $dispatcher = new RecordingDeferredDispatcher;

        (new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            $source,
            $dispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
        ))->process(91);

        self::assertSame([1], $store->appendedBatchSizes);
        self::assertSame([91], $dispatcher->requestIds);
        self::assertSame(1, $store->snapshotCount);
        self::assertSame(1, $store->chunkCount);
        self::assertSame('pending', $store->status);
        self::assertSame([2], $source->requestedLimits);
    }

    #[Test]
    public function successful_progress_resets_retry_budget_for_requests_larger_than_five_chunks(): void
    {
        $store = new InMemoryDeferredCaptureStore($this->pins());
        $source = new InMemoryDeferredSourceReader(385);
        $dispatcher = new RecordingDeferredDispatcher;
        $processor = new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            $source,
            $dispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
            maximumAttempts: 2,
        );

        for ($chunk = 0; $chunk < 385; $chunk++) {
            $processor->process(91);
        }

        self::assertSame('completed', $store->status);
        self::assertSame(385, $store->snapshotCount);
        self::assertSame(385, $store->chunkCount);
        self::assertSame(0, $store->attempts);
        self::assertNull($store->lastErrorCode);
        self::assertCount(384, $dispatcher->requestIds);
    }

    #[Test]
    public function processor_retries_with_safe_code_then_dead_letters_without_exception_text(): void
    {
        $store = new InMemoryDeferredCaptureStore($this->pins());
        $store->failAppend = true;
        $source = new InMemoryDeferredSourceReader(1);
        $dispatcher = new RecordingDeferredDispatcher;
        $processor = new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            $source,
            $dispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
            maximumAttempts: 2,
        );

        $processor->process(91);
        $processor->process(91);

        self::assertSame('dead_lettered', $store->status);
        self::assertSame('workforce_capacity_materialization_failed', $store->lastErrorCode);
        self::assertStringNotContainsString('private failure text', (string) $store->lastErrorCode);
        self::assertSame([91], $dispatcher->requestIds);
    }

    #[Test]
    public function recovered_claim_beyond_the_attempt_budget_is_dead_lettered_without_materialization(): void
    {
        $store = new InMemoryDeferredCaptureStore($this->pins());
        $store->attempts = 2;
        $source = new InMemoryDeferredSourceReader(1);
        $dispatcher = new RecordingDeferredDispatcher;

        (new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            $source,
            $dispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
            maximumAttempts: 2,
        ))->process(91);

        self::assertSame('dead_lettered', $store->status);
        self::assertSame('workforce_capacity_attempts_exhausted', $store->lastErrorCode);
        self::assertSame([], $store->appendedBatchSizes);
        self::assertSame([], $dispatcher->requestIds);
        self::assertSame([], $source->requestedLimits);
    }

    #[Test]
    public function materialization_job_contains_only_request_identity_and_uses_serviced_reports_queue(): void
    {
        $job = new MaterializeWorkforceCapacityCaptureJob(91);
        $store = new InMemoryDeferredCaptureStore($this->pins());
        $processor = new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            new InMemoryDeferredSourceReader(1),
            new RecordingDeferredDispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
        );

        $job->handle($processor);

        self::assertSame(91, $job->captureRequestId);
        self::assertSame('redis_reports', $job->connection);
        self::assertSame('reports', $job->queue);
        self::assertSame('completed', $store->status);
        self::assertSame(1, $store->snapshotCount);
    }

    #[Test]
    public function snapshot_batches_reject_non_increasing_lock_order(): void
    {
        $keys = (new InMemoryDeferredSourceReader(2))->nextKeys(91, null, 2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workforce_capacity_batch_order_invalid');

        (new WorkforceCapacitySnapshotBatchOrder)->assertKeys([$keys[1], $keys[0]]);
    }

    #[Test]
    public function exhausted_deferred_plan_is_dead_lettered_instead_of_leaving_a_claimed_lease(): void
    {
        $store = new InMemoryDeferredCaptureStore($this->pins());

        (new WorkforceCapacityDeferredCaptureProcessor(
            $store,
            new InMemoryDeferredSourceReader(0),
            new RecordingDeferredDispatcher,
            new WorkforceCapacitySnapshotEvaluatorRegistry,
        ))->process(91);

        self::assertSame('dead_lettered', $store->status);
        self::assertSame('workforce_capacity_deferred_plan_exhausted', $store->lastErrorCode);
    }

    #[Test]
    public function frozen_pins_reject_a_self_hashed_unknown_formula_version(): void
    {
        $pins = $this->pins();
        $command = json_decode($pins->commandCanonical(), true, flags: JSON_THROW_ON_ERROR);
        $command['formula_version'] = 'workforce-capacity-formula.v2';
        ksort($command, SORT_STRING);
        $canonical = json_encode($command, JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_frozen_version_invalid');

        WorkforceCapacityFrozenCapturePins::fromCanonical(
            $canonical,
            hash('sha256', $canonical),
            $pins->policyCanonical(),
            $pins->policyHash(),
            $pins->capturedAt,
            $pins->businessDate,
            WorkforceCapacityFrozenCapturePins::SOURCE_SCHEMA_VERSION,
            'workforce-capacity-formula.v2',
        );
    }

    #[Test]
    public function evaluator_registry_routes_only_the_exact_pinned_version_pair(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workforce_capacity_evaluator_version_unknown');

        (new WorkforceCapacitySnapshotEvaluatorRegistry)->resolve(
            'workforce-capacity-source.v1',
            'workforce-capacity-formula.v2',
            WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
        );
    }

    private function pins(): WorkforceCapacityFrozenCapturePins
    {
        return new WorkforceCapacityFrozenCapturePins(
            command: new WorkforceCapacityCaptureCommand(
                mutationId: 'assignment:31:deferred-test',
                organizationId: 7,
                sourceType: 'assignment',
                oldState: null,
                newState: ['id' => 31, 'organization_id' => 7, 'staff_unit_id' => 11],
                captureKind: 'change_capture',
                actorUserId: null,
                serviceActor: 'workforce-owner',
            ),
            policy: WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            capturedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
            businessDate: '2026-08-15',
        );
    }
}

final class InMemoryDeferredCaptureStore implements WorkforceCapacityDeferredCaptureStore
{
    public string $status = 'pending';

    public int $snapshotCount = 0;

    public int $chunkCount = 0;

    public ?string $lastErrorCode = null;

    public array $appendedBatchSizes = [];

    public bool $failAppend = false;

    public int $attempts = 0;

    private ?string $cohortCursor = null;

    private ?string $hashCursor = null;

    public function __construct(private readonly WorkforceCapacityFrozenCapturePins $pins) {}

    public function claim(int $captureRequestId, DateTimeImmutable $at, int $leaseSeconds): ?WorkforceCapacityDeferredCaptureClaim
    {
        if ($this->status === 'completed' || $this->status === 'dead_lettered') {
            return null;
        }
        $this->status = 'processing';
        $this->attempts++;

        return new WorkforceCapacityDeferredCaptureClaim(
            91,
            'claim-'.$this->attempts,
            $this->pins,
            $this->cohortCursor,
            $this->hashCursor,
            $this->snapshotCount,
            $this->chunkCount,
            $this->attempts,
        );
    }

    public function appendClaimedChunk(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $cohortCursor,
        string $hashCursor,
        array $snapshots,
        bool $completed,
        DateTimeImmutable $at,
    ): bool {
        if ($this->failAppend) {
            throw new LogicException('private failure text');
        }
        $this->appendedBatchSizes[] = count($snapshots);
        $this->snapshotCount += count($snapshots);
        $this->chunkCount++;
        $this->cohortCursor = $cohortCursor;
        $this->hashCursor = $hashCursor;
        $this->status = $completed ? 'completed' : 'pending';
        $this->attempts = 0;

        return true;
    }

    public function failClaim(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $safeErrorCode,
        DateTimeImmutable $retryAt,
        bool $deadLettered,
    ): bool {
        $this->lastErrorCode = $safeErrorCode;
        $this->status = $deadLettered ? 'dead_lettered' : 'pending';

        return true;
    }

    public function recoverableIds(DateTimeImmutable $at, int $limit, int $leaseSeconds): array
    {
        return [];
    }
}

final class InMemoryDeferredSourceReader implements WorkforceCapacityDeferredSourceReader
{
    public array $requestedLimits = [];

    private array $keys = [];

    public function __construct(int $monthCount)
    {
        $month = new DateTimeImmutable('2026-08-01');
        for ($index = 0; $index < $monthCount; $index++) {
            $current = $month->modify("+{$index} months");
            $this->keys[] = new WorkforceCapacityCohortKey(
                7,
                $current->modify('last day of this month')->format('Y-m-d'),
                $current->format('Y-m-01'),
                11,
                null,
            );
        }
    }

    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array
    {
        $this->requestedLimits[] = $limit;

        return array_slice(array_values(array_filter(
            $this->keys,
            static fn (WorkforceCapacityCohortKey $key): bool => $afterSortIdentity === null
                || strcmp($key->sortIdentity(), $afterSortIdentity) > 0,
        )), 0, $limit);
    }

    public function readBatch(int $captureRequestId, array $keys): array
    {
        $sources = [];
        foreach ($keys as $key) {
            $sources[$key->identity()] = [
                'staff_unit' => [
                    'id' => 11,
                    'organization_id' => 7,
                    'department_id' => 2,
                    'position_id' => 3,
                    'headcount' => '1.00',
                    'rate' => '1.0000',
                    'valid_from' => '2020-01-01',
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

        return $sources;
    }
}

final class RecordingDeferredDispatcher implements WorkforceCapacityDeferredCaptureDispatcher
{
    public array $requestIds = [];

    public function dispatchAfterCommit(int $captureRequestId): void
    {
        $this->requestIds[] = $captureRequestId;
    }
}
