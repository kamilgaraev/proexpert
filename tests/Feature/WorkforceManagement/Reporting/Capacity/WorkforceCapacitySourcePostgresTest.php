<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\EloquentWorkforceCapacityDeferredCaptureStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\EloquentWorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\PostgresWorkforceCapacityCohortLock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacitySnapshotBuilder;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class WorkforceCapacitySourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('WORKFORCE_CAPACITY_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set WORKFORCE_CAPACITY_POSTGRES_TESTS=1 to run isolated PostgreSQL workforce-capacity tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_exact_replay_is_idempotent_and_changed_source_creates_new_snapshot(): void
    {
        $fixture = $this->fixture();
        $store = $this->store();
        $snapshot = $this->snapshot($fixture);
        $cursor = str_repeat('a', 64);

        $store->appendBatch('r19-pg-replay-001', null, $cursor, [$snapshot]);
        $store->appendBatch('r19-pg-replay-001', null, $cursor, [$snapshot]);
        $store->completeCapture('r19-pg-replay-001', $fixture['organization_id'], $cursor, 1, 1);

        self::assertSame(1, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame($snapshot->itemCount, DB::table('workforce_capacity_snapshot_items')->count());
        self::assertNotNull(DB::table('workforce_capacity_snapshots')->value('sealed_at'));

        $changed = $fixture;
        $changed['source']['assignments'][0]['rate'] = '0.7500';
        $changedSnapshot = $this->snapshot($changed);
        self::assertNotSame($snapshot->sourceHash, $changedSnapshot->sourceHash);
        $store->appendBatch('r19-pg-changed-001', null, str_repeat('b', 64), [$changedSnapshot]);

        self::assertSame(2, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame(2, DB::table('workforce_capacity_snapshots')->distinct()->count('source_hash'));
    }

    public function test_identical_sources_from_distinct_requests_keep_exact_snapshot_lineage(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $store = $this->store();

        $store->appendBatch('r19-pg-lineage-request-001', null, str_repeat('8', 64), [$snapshot]);
        $store->appendBatch('r19-pg-lineage-request-002', null, str_repeat('9', 64), [$snapshot]);

        $requests = DB::table('workforce_capacity_capture_requests')
            ->whereIn('mutation_id', ['r19-pg-lineage-request-001', 'r19-pg-lineage-request-002'])
            ->orderBy('mutation_id')
            ->get(['id', 'snapshot_count']);
        $snapshotRequestIds = DB::table('workforce_capacity_snapshots')
            ->whereIn('capture_mutation_id', ['r19-pg-lineage-request-001', 'r19-pg-lineage-request-002'])
            ->orderBy('capture_mutation_id')
            ->pluck('capture_request_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertCount(2, $requests);
        self::assertSame([1, 1], $requests->pluck('snapshot_count')->map(static fn ($count): int => (int) $count)->all());
        self::assertSame($requests->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $snapshotRequestIds);
    }

    public function test_postgresql_guards_reject_mutation_and_nested_personal_data(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $this->store()->appendBatch(
            'r19-pg-guards-001',
            null,
            str_repeat('c', 64),
            [$snapshot],
        );
        $snapshotId = (int) DB::table('workforce_capacity_snapshots')->value('id');
        $itemId = (int) DB::table('workforce_capacity_snapshot_items')->value('id');

        $this->assertSqlState($this->captureQueryException(static function () use ($snapshotId): void {
            DB::table('workforce_capacity_snapshots')->where('id', $snapshotId)->update([
                'assigned_fte' => '99.0000',
            ]);
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($itemId): void {
            DB::table('workforce_capacity_snapshot_items')->where('id', $itemId)->delete();
        }), '55000');

        $containsRestricted = DB::selectOne(
            'SELECT workforce_capacity_json_has_forbidden(?::jsonb) AS rejected',
            [json_encode(['safe' => ['nested' => ['salary' => '100000.00']]], JSON_THROW_ON_ERROR)],
        );
        self::assertTrue((bool) $containsRestricted?->rejected);
    }

    public function test_null_project_bucket_is_persisted_without_deriving_a_project(): void
    {
        $fixture = $this->fixture(projectBucket: false);
        $snapshot = $this->snapshot($fixture);

        $this->store()->appendBatch(
            'r19-pg-null-bucket-001',
            null,
            str_repeat('d', 64),
            [$snapshot],
        );

        self::assertNull(DB::table('workforce_capacity_snapshots')->value('project_id'));
        self::assertSame(
            0,
            DB::table('workforce_capacity_snapshot_items')->whereNotNull('project_id')->count(),
        );
    }

    public function test_snapshot_and_owner_change_roll_back_together(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $store = $this->store();

        try {
            DB::transaction(static function () use ($fixture, $snapshot, $store): void {
                DB::table('workforce_staff_units')
                    ->where('id', $fixture['staff_unit_id'])
                    ->update(['headcount' => '2.00']);
                $store->appendBatch(
                    'r19-pg-owner-rollback-001',
                    null,
                    str_repeat('e', 64),
                    [$snapshot],
                );

                throw new RuntimeException('workforce_capacity_owner_rollback_sentinel');
            });
            self::fail('The owner transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('workforce_capacity_owner_rollback_sentinel', $exception->getMessage());
        }

        self::assertSame(
            '1.00',
            (string) DB::table('workforce_staff_units')->where('id', $fixture['staff_unit_id'])->value('headcount'),
        );
        self::assertSame(0, DB::table('workforce_capacity_snapshots')->count());
        self::assertSame(0, DB::table('workforce_capacity_capture_requests')->count());
    }

    public function test_database_recomputes_policy_hash_and_rejects_cross_organization_project_lineage(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $payload = $snapshot->toPersistence();
        unset($payload['semantic_label']);
        $mutationId = 'r19-pg-direct-guard-001';
        $captureRequestId = $this->insertSyncCaptureRequest(
            $fixture['organization_id'],
            $mutationId,
            $snapshot->capturedAt,
        );
        $payload += [
            'capture_request_id' => $captureRequestId,
            'capture_mutation_id' => $mutationId,
            'capture_cursor' => str_repeat('f', 64),
            'sealed_at' => null,
        ];

        $badPolicy = $payload;
        $badPolicy['policy_hash'] = str_repeat('0', 64);
        $this->assertSqlState($this->captureQueryException(static function () use ($badPolicy): void {
            DB::table('workforce_capacity_snapshots')->insert($badPolicy);
        }), '23514');

        $foreignOrganization = Organization::factory()->create(['workforce_timezone' => 'Europe/Moscow']);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $badLineage = $payload;
        $badLineage['project_id'] = (int) $foreignProject->id;
        $this->assertSqlState($this->captureQueryException(static function () use ($badLineage): void {
            DB::table('workforce_capacity_snapshots')->insert($badLineage);
        }), '23514');

        self::assertSame(0, DB::table('workforce_capacity_snapshots')->count());
    }

    public function test_frozen_range_rejects_cross_organization_project_before_request_seal(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-frozen-range-cross-org-001');
        $foreignOrganization = Organization::factory()->create(['workforce_timezone' => 'Europe/Moscow']);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $exception = $this->captureQueryException(static function () use (
            $requestId,
            $pins,
            $fixture,
            $foreignProject,
        ): void {
            DB::table('workforce_capacity_capture_ranges')->insert([
                'capture_request_id' => $requestId,
                'organization_id' => $fixture['organization_id'],
                'staff_unit_id' => $fixture['staff_unit_id'],
                'project_id' => (int) $foreignProject->id,
                'from_month' => '2026-08-01',
                'through_month' => '2026-08-01',
                'created_at' => $pins->capturedAt,
            ]);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('frozen range invalid', $exception->getMessage());
    }

    public function test_frozen_snapshot_uses_request_policy_after_organization_timezone_changes(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-frozen-timezone-001');
        DB::table('workforce_capacity_capture_ranges')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'from_month' => '2026-08-01',
            'through_month' => '2026-08-01',
            'created_at' => $pins->capturedAt,
        ]);
        DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'pending',
            'range_count' => 1,
            'source_row_count' => 0,
            'frozen_at' => $pins->capturedAt,
            'available_at' => $pins->capturedAt,
        ]);
        DB::table('organizations')->where('id', $fixture['organization_id'])->update([
            'workforce_timezone' => 'Asia/Yekaterinburg',
        ]);
        $snapshot = (new WorkforceCapacitySnapshotBuilder($pins->policy))->build(
            key: new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                '2026-08-01',
                '2026-08-01',
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ),
            captureKind: 'change_capture',
            capturedAt: $pins->capturedAt,
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );
        $payload = $snapshot->toPersistence();
        unset($payload['semantic_label']);

        DB::table('workforce_capacity_snapshots')->insert([
            ...$payload,
            'capture_request_id' => $requestId,
            'capture_mutation_id' => $pins->command->mutationId,
            'capture_cursor' => hash('sha256', $pins->command->mutationId),
            'sealed_at' => null,
        ]);

        self::assertSame(1, DB::table('workforce_capacity_snapshots')->where('capture_request_id', $requestId)->count());
    }

    public function test_capture_request_state_machine_rejects_pending_completion_and_cursor_tampering(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-state-machine-001');
        DB::table('workforce_capacity_capture_ranges')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'from_month' => '2026-08-01',
            'through_month' => '2026-08-01',
            'created_at' => $pins->capturedAt,
        ]);
        DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'pending',
            'range_count' => 1,
            'source_row_count' => 0,
            'frozen_at' => $pins->capturedAt,
            'available_at' => $pins->capturedAt,
        ]);

        $completion = $this->captureQueryException(static function () use ($requestId, $pins): void {
            DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
                'status' => 'completed',
                'completed_at' => $pins->capturedAt,
            ]);
        });
        $this->assertSqlState($completion, '23514');

        $cursor = $this->captureQueryException(static function () use ($requestId): void {
            DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
                'current_cursor' => str_repeat('a', 64),
                'snapshot_count' => 1,
            ]);
        });
        $this->assertSqlState($cursor, '23514');
    }

    public function test_synchronous_capture_request_rejects_nonzero_progress_on_insert(): void
    {
        $fixture = $this->fixture();
        $exception = $this->captureQueryException(function () use ($fixture): void {
            DB::table('workforce_capacity_capture_requests')->insert([
                'organization_id' => $fixture['organization_id'],
                'mutation_id' => 'r19-pg-invalid-sync-progress-001',
                'status' => 'processing',
                'snapshot_count' => 1,
                'started_at' => $this->utcNow(),
            ]);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('synchronous capture invalid', $exception->getMessage());
    }

    public function test_owner_revision_is_monotonic_for_multiple_updates_in_the_same_second(): void
    {
        $fixture = $this->fixture();
        $fixedTimestamp = '2026-08-15 09:00:00+00';

        DB::table('workforce_employee_assignments')->where('id', $fixture['assignment_id'])->update([
            'rate' => '0.7500',
            'updated_at' => $fixedTimestamp,
        ]);
        $firstRevision = (int) DB::table('workforce_employee_assignments')
            ->where('id', $fixture['assignment_id'])
            ->value('workforce_capacity_revision');
        DB::table('workforce_employee_assignments')->where('id', $fixture['assignment_id'])->update([
            'rate' => '1.0000',
            'updated_at' => $fixedTimestamp,
        ]);
        $secondRevision = (int) DB::table('workforce_employee_assignments')
            ->where('id', $fixture['assignment_id'])
            ->value('workforce_capacity_revision');

        self::assertSame(1, $firstRevision);
        self::assertSame(2, $secondRevision);
    }

    public function test_zero_affected_frozen_capture_seals_as_completed_without_dispatchable_work(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-zero-affected-001');

        $updated = DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'completed',
            'range_count' => 0,
            'source_row_count' => 0,
            'frozen_at' => $pins->capturedAt,
            'completed_at' => $pins->capturedAt,
        ]);

        self::assertSame(1, $updated);
        $request = DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->first();
        self::assertSame('completed', $request?->status);
        self::assertSame(0, (int) $request?->range_count);
        self::assertSame(0, (int) $request?->source_row_count);
    }

    public function test_real_deferred_store_claim_reclaim_cas_progress_reset_and_exhaustion(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-real-deferred-store-001');
        DB::table('workforce_capacity_capture_ranges')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'from_month' => '2026-08-01',
            'through_month' => '2026-08-01',
            'created_at' => $pins->capturedAt,
        ]);
        DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'pending',
            'range_count' => 1,
            'source_row_count' => 0,
            'frozen_at' => $pins->capturedAt,
            'available_at' => $pins->capturedAt,
        ]);
        $store = new EloquentWorkforceCapacityDeferredCaptureStore(new ProgressOnlyWorkforceCapacitySnapshotStore);
        $firstAt = $pins->capturedAt->modify('+1 second');
        $first = $store->claim($requestId, $firstAt, 960);
        self::assertNotNull($first);
        self::assertSame(1, $first->attemptCount);
        self::assertNull($store->claim($requestId, $firstAt->modify('+100 seconds'), 960));

        $reclaimed = $store->claim($requestId, $firstAt->modify('+961 seconds'), 960);
        self::assertNotNull($reclaimed);
        self::assertSame(2, $reclaimed->attemptCount);
        self::assertFalse($store->failClaim($first, 'stale_claim_must_not_win', $firstAt, false));

        $snapshot = (new WorkforceCapacitySnapshotBuilder($pins->policy))->build(
            key: new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                '2026-08-01',
                '2026-08-01',
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ),
            captureKind: 'change_capture',
            capturedAt: $pins->capturedAt,
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );
        self::assertTrue($store->appendClaimedChunk(
            $reclaimed,
            $snapshot->key->sortIdentity(),
            str_repeat('a', 64),
            [$snapshot],
            false,
            $firstAt->modify('+962 seconds'),
        ));
        $afterProgress = $store->claim($requestId, $firstAt->modify('+963 seconds'), 960);
        self::assertNotNull($afterProgress);
        self::assertSame(1, $afterProgress->attemptCount);
        self::assertTrue($store->failClaim(
            $afterProgress,
            'workforce_capacity_materialization_failed',
            $firstAt->modify('+964 seconds'),
            false,
        ));
        $last = $store->claim($requestId, $firstAt->modify('+965 seconds'), 960);
        self::assertNotNull($last);
        self::assertSame(2, $last->attemptCount);
        self::assertTrue($store->failClaim(
            $last,
            'workforce_capacity_materialization_failed',
            $firstAt->modify('+966 seconds'),
            true,
        ));

        $request = DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->first();
        self::assertSame('dead_lettered', $request?->status);
        self::assertSame(2, (int) $request?->attempt_count);
        self::assertSame(1, (int) $request?->snapshot_count);
        self::assertSame(1, (int) $request?->chunk_count);
    }

    public function test_real_deferred_store_rolls_back_progress_when_snapshot_persistence_crashes(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-real-deferred-crash-001');
        DB::table('workforce_capacity_capture_ranges')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'from_month' => '2026-08-01',
            'through_month' => '2026-08-01',
            'created_at' => $pins->capturedAt,
        ]);
        DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'pending',
            'range_count' => 1,
            'source_row_count' => 0,
            'frozen_at' => $pins->capturedAt,
            'available_at' => $pins->capturedAt,
        ]);
        $store = new EloquentWorkforceCapacityDeferredCaptureStore(new CrashingWorkforceCapacitySnapshotStore);
        $claim = $store->claim($requestId, $pins->capturedAt->modify('+1 second'), 960);
        self::assertNotNull($claim);
        $snapshot = (new WorkforceCapacitySnapshotBuilder($pins->policy))->build(
            key: new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                '2026-08-01',
                '2026-08-01',
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ),
            captureKind: 'change_capture',
            capturedAt: $pins->capturedAt,
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );

        try {
            $store->appendClaimedChunk(
                $claim,
                $snapshot->key->sortIdentity(),
                str_repeat('b', 64),
                [$snapshot],
                false,
                $pins->capturedAt->modify('+2 seconds'),
            );
            self::fail('The simulated snapshot crash must escape the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('workforce_capacity_snapshot_crash_fixture', $exception->getMessage());
        }

        $request = DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->first();
        self::assertSame('processing', $request?->status);
        self::assertSame($claim->claimToken, $request?->claim_token);
        self::assertNull($request?->current_cursor);
        self::assertNull($request?->cohort_cursor);
        self::assertSame(0, (int) $request?->snapshot_count);
        self::assertSame(0, (int) $request?->chunk_count);
    }

    public function test_competing_connections_serialize_on_the_production_cohort_lock(): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')) {
            if (getenv('CI') === 'true') {
                self::fail('CI PostgreSQL cohort-lock gate requires pcntl and posix extensions.');
            }

            $this->markTestSkipped('Requires pcntl and posix extensions for a real PostgreSQL process race.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workforce-capacity-race-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $connections = ['workforce_capacity_winner', 'workforce_capacity_contender', 'workforce_capacity_observer'];
        $originalDefault = (string) config('database.default');
        $children = [];
        $winner = null;

        try {
            $winner = $harness->independentConnection('workforce_capacity_winner');
            $harness->independentConnection('workforce_capacity_contender');
            $observer = $harness->independentConnection('workforce_capacity_observer');
            if (! $winner instanceof Connection || ! $observer instanceof Connection) {
                throw new RuntimeException('Workforce capacity race requires Laravel PostgreSQL connections.');
            }
            $key = new WorkforceCapacityCohortKey(7, '2026-08-15', '2026-08-01', 11, null);
            $winner->beginTransaction();
            DB::setDefaultConnection('workforce_capacity_winner');
            (new PostgresWorkforceCapacityCohortLock)->acquire($key);
            DB::setDefaultConnection('workforce_capacity_contender');
            $children[] = $harness->spawn(1, static function () use ($key): array {
                DB::transaction(static function () use ($key): void {
                    (new PostgresWorkforceCapacityCohortLock)->acquire($key);
                });

                return ['outcome' => 'locked_after_winner'];
            });
            DB::setDefaultConnection($originalDefault);

            $harness->release(1);
            $backendPid = $harness->waitForWorkerBackendPid(1);
            $harness->waitForPostgresWait($observer, $backendPid, 'advisory');
            $winner->commit();
            $harness->waitForChildren($children);

            self::assertSame('locked_after_winner', $harness->result(1)['outcome'] ?? null);
        } finally {
            DB::setDefaultConnection($originalDefault);
            $failure = null;
            if ($winner instanceof Connection && $winner->transactionLevel() > 0) {
                $harness->cleanupStep(static fn () => $winner->rollBack(), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->terminateAndReap($children), $failure);
            foreach ($connections as $connection) {
                $harness->cleanupStep(static fn () => DB::purge($connection), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->cleanup(), $failure);

            if ($failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    public function test_item_trigger_rejects_coherent_pii_and_sealed_employee_lineage_substitution(): void
    {
        $fixture = $this->fixture();
        $snapshot = $this->snapshot($fixture);
        $assignmentItem = collect($snapshot->items)
            ->first(static fn ($item): bool => $item->sourceType === 'assignment');
        self::assertNotNull($assignmentItem);

        $piiException = $this->captureQueryException(function () use ($snapshot, $assignmentItem): void {
            $snapshotId = $this->insertUnsealedSnapshot($snapshot, 'r19-pg-pii-guard-001');
            $item = $assignmentItem->toPersistence(1);
            $content = json_decode($item['content_canonical'], true, 512, JSON_THROW_ON_ERROR);
            $content['evidence']['salary'] = '100000.00';
            $item['content_canonical'] = $this->canonicalJson($content);
            $item['content_hash'] = hash('sha256', $item['content_canonical']);
            DB::table('workforce_capacity_snapshot_items')->insert([
                ...$item,
                'workforce_capacity_snapshot_id' => $snapshotId,
                'organization_id' => $snapshot->key->organizationId,
                'staff_unit_id' => $snapshot->key->staffUnitId,
                'project_id' => $snapshot->key->projectId,
                'month_start' => $snapshot->key->monthStart,
                'created_at' => $snapshot->capturedAt,
            ]);
        });
        $this->assertSqlState($piiException, '23514');
        self::assertStringContainsString('evidence payload invalid', $piiException->getMessage());

        $foreignEmployeeId = (int) DB::table('workforce_employees')->insertGetId([
            'organization_id' => $fixture['organization_id'],
            'personnel_number' => 'R19-PG-EMP-FOREIGN',
            'last_name' => 'Lineage',
            'first_name' => 'Mismatch',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $this->utcNow(),
            'updated_at' => $this->utcNow(),
        ]);
        $lineageException = $this->captureQueryException(function () use (
            $snapshot,
            $assignmentItem,
            $foreignEmployeeId,
        ): void {
            $snapshotId = $this->insertUnsealedSnapshot($snapshot, 'r19-pg-lineage-guard-001');
            $item = $assignmentItem->toPersistence(1);
            $content = json_decode($item['content_canonical'], true, 512, JSON_THROW_ON_ERROR);
            $content['sealed_employee_id'] = $foreignEmployeeId;
            $item['sealed_employee_id'] = $foreignEmployeeId;
            $item['content_canonical'] = $this->canonicalJson($content);
            $item['content_hash'] = hash('sha256', $item['content_canonical']);
            DB::table('workforce_capacity_snapshot_items')->insert([
                ...$item,
                'workforce_capacity_snapshot_id' => $snapshotId,
                'organization_id' => $snapshot->key->organizationId,
                'staff_unit_id' => $snapshot->key->staffUnitId,
                'project_id' => $snapshot->key->projectId,
                'month_start' => $snapshot->key->monthStart,
                'created_at' => $snapshot->capturedAt,
            ]);
        });
        $this->assertSqlState($lineageException, '23514');
        self::assertStringContainsString('assignment lineage invalid', $lineageException->getMessage());
    }

    public function test_frozen_item_rejects_assignment_from_another_effective_period(): void
    {
        $fixture = $this->fixture();
        [$requestId, $pins] = $this->preparingRequest($fixture, 'r19-pg-frozen-period-001');
        DB::table('workforce_capacity_capture_ranges')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'from_month' => '2026-08-01',
            'through_month' => '2026-08-01',
            'created_at' => $pins->capturedAt,
        ]);
        $invalidAssignment = $fixture['source']['assignments'][0];
        $invalidAssignment['valid_from'] = '2026-09-01';
        $payloadCanonical = $this->canonicalJson($invalidAssignment);
        DB::table('workforce_capacity_frozen_source_rows')->insert([
            'capture_request_id' => $requestId,
            'organization_id' => $fixture['organization_id'],
            'source_type' => 'assignment',
            'source_id' => $fixture['assignment_id'],
            'source_key' => 'assignment:'.$fixture['assignment_id'],
            'staff_unit_id' => $fixture['staff_unit_id'],
            'project_id' => $fixture['project_id'],
            'employee_id' => $fixture['employee_id'],
            'schedule_id' => $invalidAssignment['work_schedule_id'],
            'valid_from' => $invalidAssignment['valid_from'],
            'valid_to' => $invalidAssignment['valid_to'],
            'work_date' => null,
            'payload' => $invalidAssignment,
            'payload_canonical' => $payloadCanonical,
            'payload_hash' => hash('sha256', $payloadCanonical),
            'created_at' => $pins->capturedAt,
        ]);
        DB::table('workforce_capacity_capture_requests')->where('id', $requestId)->update([
            'status' => 'pending',
            'range_count' => 1,
            'source_row_count' => 1,
            'frozen_at' => $pins->capturedAt,
            'available_at' => $pins->capturedAt,
        ]);
        $snapshot = (new WorkforceCapacitySnapshotBuilder($pins->policy))->build(
            key: new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                '2026-08-01',
                '2026-08-01',
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ),
            captureKind: 'change_capture',
            capturedAt: $pins->capturedAt,
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );
        $snapshotPayload = $snapshot->toPersistence();
        unset($snapshotPayload['semantic_label']);
        $snapshotId = (int) DB::table('workforce_capacity_snapshots')->insertGetId([
            ...$snapshotPayload,
            'capture_request_id' => $requestId,
            'capture_mutation_id' => $pins->command->mutationId,
            'capture_cursor' => hash('sha256', $pins->command->mutationId),
            'sealed_at' => null,
        ]);
        $assignmentItem = collect($snapshot->items)
            ->first(static fn (WorkforceCapacityEvidenceItem $item): bool => $item->sourceType === 'assignment');
        self::assertInstanceOf(WorkforceCapacityEvidenceItem::class, $assignmentItem);
        $lineage = $assignmentItem->lineage;
        $lineage['effective_from'] = $invalidAssignment['valid_from'];
        $evidence = $assignmentItem->evidence;
        $evidence['valid_from'] = $invalidAssignment['valid_from'];
        $sourceCanonical = $this->canonicalJson(['source' => $invalidAssignment, 'type' => 'assignment']);
        $revision = hash('sha256', $sourceCanonical);
        $contentCanonical = $this->canonicalJson([
            'evidence' => $evidence,
            'lineage' => $lineage,
            'revision' => $revision,
            'sealed_employee_id' => $fixture['employee_id'],
            'source_id' => $fixture['assignment_id'],
            'type' => 'assignment',
        ]);
        $invalidItem = new WorkforceCapacityEvidenceItem(
            sourceType: 'assignment',
            sourceId: $fixture['assignment_id'],
            sourceRevisionHash: $revision,
            sourceCanonical: $sourceCanonical,
            contentHash: hash('sha256', $contentCanonical),
            lineage: $lineage,
            evidence: $evidence,
            contentCanonical: $contentCanonical,
            sealedEmployeeId: $fixture['employee_id'],
        );

        $exception = $this->captureQueryException(static function () use ($invalidItem, $snapshotId, $snapshot): void {
            DB::table('workforce_capacity_snapshot_items')->insert([
                ...$invalidItem->toPersistence(1),
                'workforce_capacity_snapshot_id' => $snapshotId,
                'organization_id' => $snapshot->key->organizationId,
                'staff_unit_id' => $snapshot->key->staffUnitId,
                'project_id' => $snapshot->key->projectId,
                'month_start' => $snapshot->key->monthStart,
                'created_at' => $snapshot->capturedAt,
            ]);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('frozen evidence lineage invalid', $exception->getMessage());
    }

    public function test_multi_month_store_keeps_chunk_cursor_and_progress_continuity(): void
    {
        $fixture = $this->fixture();
        $snapshots = [];
        for ($offset = 0; $offset < 129; $offset++) {
            $month = (new DateTimeImmutable('2026-08-01'))->modify("+{$offset} months");
            $snapshots[] = $this->snapshotForKey($fixture, new WorkforceCapacityCohortKey(
                $fixture['organization_id'],
                $month->modify('last day of this month')->format('Y-m-d'),
                $month->format('Y-m-01'),
                $fixture['staff_unit_id'],
                $fixture['project_id'],
            ));
        }
        $store = $this->store();
        $mutationId = 'r19-pg-chunk-continuity-001';
        $cursor1 = str_repeat('1', 64);
        $cursor2 = str_repeat('2', 64);
        $cursor3 = str_repeat('3', 64);

        $store->appendBatch($mutationId, null, $cursor1, array_slice($snapshots, 0, 64));
        $store->appendBatch($mutationId, $cursor1, $cursor2, array_slice($snapshots, 64, 64));
        $store->appendBatch($mutationId, $cursor2, $cursor3, array_slice($snapshots, 128, 1));
        $store->completeCapture($mutationId, $fixture['organization_id'], $cursor3, 129, 3);

        $request = DB::table('workforce_capacity_capture_requests')
            ->where('organization_id', $fixture['organization_id'])
            ->where('mutation_id', $mutationId)
            ->first();
        self::assertSame('completed', $request?->status);
        self::assertSame($cursor3, $request?->current_cursor);
        self::assertSame(129, (int) $request?->snapshot_count);
        self::assertSame(3, (int) $request?->chunk_count);
        self::assertSame(129, DB::table('workforce_capacity_snapshots')
            ->where('organization_id', $fixture['organization_id'])
            ->where('capture_mutation_id', $mutationId)
            ->count());
    }

    public function test_inactive_expired_and_missing_schedule_states_persist_explicit_gaps(): void
    {
        $fixture = $this->fixture();
        $inactive = $fixture;
        $inactive['source']['staff_unit']['is_active'] = false;
        $expired = $fixture;
        $expired['source']['staff_unit']['valid_to'] = '2026-07-31';
        $missingSchedule = $fixture;
        $missingSchedule['source']['assignments'][0]['work_schedule_id'] = null;
        $missingSchedule['source']['schedules'] = [];

        $snapshots = [
            $this->snapshotForKey($inactive, new WorkforceCapacityCohortKey(
                $fixture['organization_id'], '2026-08-02', '2026-08-01',
                $fixture['staff_unit_id'], $fixture['project_id'],
            )),
            $this->snapshotForKey($expired, new WorkforceCapacityCohortKey(
                $fixture['organization_id'], '2026-08-03', '2026-08-01',
                $fixture['staff_unit_id'], $fixture['project_id'],
            )),
            $this->snapshotForKey($missingSchedule, new WorkforceCapacityCohortKey(
                $fixture['organization_id'], '2026-08-04', '2026-08-01',
                $fixture['staff_unit_id'], $fixture['project_id'],
            )),
        ];

        $this->store()->appendBatch(
            'r19-pg-explicit-gaps-001',
            null,
            str_repeat('4', 64),
            $snapshots,
        );

        $gaps = DB::table('workforce_capacity_snapshots')
            ->where('capture_mutation_id', 'r19-pg-explicit-gaps-001')
            ->orderBy('as_of_date')
            ->pluck('gap_codes')
            ->map(static fn (string $value): array => json_decode($value, true, 512, JSON_THROW_ON_ERROR))
            ->all();
        self::assertContains('inactive_staff_unit', $gaps[0]);
        self::assertContains('inactive_staff_unit', $gaps[1]);
        self::assertContains('missing_schedule', $gaps[2]);
    }

    public function test_manual_and_system_actor_contracts_are_enforced_by_postgresql(): void
    {
        $fixture = $this->fixture();
        $actor = User::factory()->create(['current_organization_id' => $fixture['organization_id']]);
        DB::table('organization_user')->insert([
            'organization_id' => $fixture['organization_id'],
            'user_id' => $actor->id,
            'is_owner' => false,
            'is_active' => true,
            'created_at' => $this->utcNow(),
            'updated_at' => $this->utcNow(),
        ]);
        $manual = (new WorkforceCapacitySnapshotBuilder(WorkforceCapacityPolicyDefinition::v1('Europe/Moscow')))
            ->build(
                key: new WorkforceCapacityCohortKey(
                    $fixture['organization_id'], '2026-08-05', '2026-08-01',
                    $fixture['staff_unit_id'], $fixture['project_id'],
                ),
                captureKind: 'manual_recompute',
                capturedAt: $this->utcNow(),
                actorUserId: (int) $actor->id,
                serviceActor: null,
                source: $fixture['source'],
            );
        $this->store()->appendBatch('r19-pg-manual-actor-001', null, str_repeat('5', 64), [$manual]);
        self::assertSame(1, DB::table('workforce_capacity_snapshots')
            ->where('capture_mutation_id', 'r19-pg-manual-actor-001')->count());

        $foreignActor = User::factory()->create();
        $invalidManual = (new WorkforceCapacitySnapshotBuilder(WorkforceCapacityPolicyDefinition::v1('Europe/Moscow')))
            ->build(
                key: new WorkforceCapacityCohortKey(
                    $fixture['organization_id'], '2026-08-06', '2026-08-01',
                    $fixture['staff_unit_id'], $fixture['project_id'],
                ),
                captureKind: 'manual_recompute',
                capturedAt: $this->utcNow(),
                actorUserId: (int) $foreignActor->id,
                serviceActor: null,
                source: $fixture['source'],
            );
        $this->assertSqlState($this->captureQueryException(function () use ($invalidManual): void {
            $this->store()->appendBatch(
                'r19-pg-invalid-manual-actor-001', null, str_repeat('6', 64), [$invalidManual],
            );
        }), '42501');

        $validSystem = $this->snapshotForKey($fixture, new WorkforceCapacityCohortKey(
            $fixture['organization_id'], '2026-08-07', '2026-08-01',
            $fixture['staff_unit_id'], $fixture['project_id'],
        ));
        $this->assertSqlState($this->captureQueryException(function () use ($actor, $validSystem): void {
            $payload = $validSystem->toPersistence();
            unset($payload['semantic_label']);
            $mutationId = 'r19-pg-invalid-system-actor-001';
            $captureRequestId = $this->insertSyncCaptureRequest(
                $validSystem->key->organizationId,
                $mutationId,
                $validSystem->capturedAt,
            );
            DB::table('workforce_capacity_snapshots')->insert([
                ...$payload,
                'actor_user_id' => (int) $actor->id,
                'capture_request_id' => $captureRequestId,
                'capture_mutation_id' => $mutationId,
                'capture_cursor' => str_repeat('7', 64),
                'sealed_at' => null,
            ]);
        }), '42501');
    }

    public function test_generic_employee_update_cannot_bypass_dedicated_lifecycle_capture(): void
    {
        $fixture = $this->fixture();

        try {
            $this->app->make(WorkforceEmployeeService::class)->update(
                $fixture['organization_id'],
                $fixture['employee_id'],
                ['employment_status' => 'dismissed', 'dismissal_date' => '2026-08-01'],
            );
            self::fail('Generic employee update must reject lifecycle fields.');
        } catch (DomainException) {
            self::assertSame(
                'active',
                DB::table('workforce_employees')->where('id', $fixture['employee_id'])->value('employment_status'),
            );
            self::assertSame(
                'active',
                DB::table('workforce_employee_assignments')->where('id', $fixture['assignment_id'])->value('status'),
            );
            self::assertSame(0, DB::table('workforce_capacity_snapshots')->count());
        }
    }

    public function test_production_planner_preserves_old_and_new_project_ranges(): void
    {
        $fixture = $this->fixture();
        $newProject = Project::factory()->create(['organization_id' => $fixture['organization_id']]);
        $command = new WorkforceCapacityCaptureCommand(
            mutationId: 'assignment:'.$fixture['assignment_id'].':pg-old-new-range',
            organizationId: $fixture['organization_id'],
            sourceType: 'assignment',
            oldState: [
                'id' => $fixture['assignment_id'],
                'organization_id' => $fixture['organization_id'],
                'employee_id' => $fixture['employee_id'],
                'staff_unit_id' => $fixture['staff_unit_id'],
                'project_id' => $fixture['project_id'],
                'valid_from' => '2026-08-01',
                'valid_to' => '2026-10-31',
            ],
            newState: [
                'id' => $fixture['assignment_id'],
                'organization_id' => $fixture['organization_id'],
                'employee_id' => $fixture['employee_id'],
                'staff_unit_id' => $fixture['staff_unit_id'],
                'project_id' => (int) $newProject->id,
                'valid_from' => '2026-09-01',
                'valid_to' => '2026-09-30',
            ],
            captureKind: 'change_capture',
            actorUserId: null,
            serviceActor: 'workforce-owner',
        );

        $keys = iterator_to_array($this->app->make(WorkforceCapacityCurrentSource::class)
            ->affectedCohorts($command, '2026-08-01'));
        $identities = array_map(static fn (WorkforceCapacityCohortKey $key): string => $key->identity(), $keys);

        self::assertContains(
            $fixture['organization_id'].':2026-08-01:'.$fixture['staff_unit_id'].':'.$fixture['project_id'],
            $identities,
        );
        self::assertContains(
            $fixture['organization_id'].':2026-09-01:'.$fixture['staff_unit_id'].':'.(int) $newProject->id,
            $identities,
        );
        self::assertContains(
            $fixture['organization_id'].':2026-10-01:'.$fixture['staff_unit_id'].':'.$fixture['project_id'],
            $identities,
        );
    }

    private function fixture(bool $projectBucket = true): array
    {
        $organization = Organization::factory()->create(['workforce_timezone' => 'Europe/Moscow']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $now = $this->utcNow()->format('Y-m-d H:i:s.uP');
        $departmentId = (int) DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-DEP',
            'name' => 'Capacity contract',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = (int) DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-POS',
            'name' => 'Capacity contract',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleId = (int) DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $organization->id,
            'code' => 'R19-PG-SCHEDULE',
            'name' => 'Explicit weekly schedule',
            'schedule_type' => 'weekly',
            'hours_per_day' => '8.00',
            'week_pattern' => json_encode([
                '1' => '8.00', '2' => '8.00', '3' => '8.00', '4' => '8.00', '5' => '8.00',
                '6' => '0.00', '7' => '0.00',
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffUnitId = (int) DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'R19-PG-UNIT',
            'headcount' => '1.00',
            'rate' => '1.0000',
            'base_salary' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $employeeId = (int) DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'R19-PG-EMP',
            'last_name' => 'Capacity',
            'first_name' => 'Contract',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assignmentProjectId = $projectBucket ? (int) $project->id : null;
        $assignmentId = (int) DB::table('workforce_employee_assignments')->insertGetId([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $assignmentProjectId,
            'work_schedule_id' => $scheduleId,
            'rate' => '1.0000',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => $assignmentProjectId,
            'staff_unit_id' => $staffUnitId,
            'employee_id' => $employeeId,
            'assignment_id' => $assignmentId,
            'source' => [
                'staff_unit' => [
                    'id' => $staffUnitId,
                    'organization_id' => (int) $organization->id,
                    'department_id' => $departmentId,
                    'position_id' => $positionId,
                    'headcount' => '1.00',
                    'rate' => '1.0000',
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'is_active' => true,
                    'deleted_at' => null,
                ],
                'assignments' => [[
                    'id' => $assignmentId,
                    'organization_id' => (int) $organization->id,
                    'employee_id' => $employeeId,
                    'staff_unit_id' => $staffUnitId,
                    'project_id' => $assignmentProjectId,
                    'work_schedule_id' => $scheduleId,
                    'rate' => '1.0000',
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'status' => 'active',
                    'deleted_at' => null,
                ]],
                'schedules' => [[
                    'id' => $scheduleId,
                    'organization_id' => (int) $organization->id,
                    'schedule_type' => 'weekly',
                    'week_pattern' => [
                        '1' => '8.00', '2' => '8.00', '3' => '8.00', '4' => '8.00', '5' => '8.00',
                        '6' => '0.00', '7' => '0.00',
                    ],
                    'is_active' => true,
                    'deleted_at' => null,
                ]],
                'schedule_days' => [],
                'absences' => [],
                'business_trips' => [],
                'employee_lifecycle' => [[
                    'id' => $employeeId,
                    'employee_id' => $employeeId,
                    'employment_status' => 'active',
                    'dismissal_date' => null,
                ]],
                'gaps' => [],
            ],
        ];
    }

    private function snapshot(array $fixture): WorkforceCapacitySnapshot
    {
        return $this->snapshotForKey($fixture, new WorkforceCapacityCohortKey(
            $fixture['organization_id'],
            '2026-08-01',
            '2026-08-01',
            $fixture['staff_unit_id'],
            $fixture['project_id'],
        ));
    }

    private function preparingRequest(array $fixture, string $mutationId): array
    {
        $pins = new WorkforceCapacityFrozenCapturePins(
            command: new WorkforceCapacityCaptureCommand(
                mutationId: $mutationId,
                organizationId: $fixture['organization_id'],
                sourceType: 'assignment',
                oldState: null,
                newState: [
                    'id' => $fixture['assignment_id'],
                    'organization_id' => $fixture['organization_id'],
                    'staff_unit_id' => $fixture['staff_unit_id'],
                    'project_id' => $fixture['project_id'],
                ],
                captureKind: 'change_capture',
                actorUserId: null,
                serviceActor: 'workforce-owner',
            ),
            policy: WorkforceCapacityPolicyDefinition::v1('Europe/Moscow'),
            capturedAt: $this->utcNow(),
            businessDate: '2026-08-01',
        );
        $requestId = (int) DB::table('workforce_capacity_capture_requests')->insertGetId([
            'organization_id' => $fixture['organization_id'],
            'mutation_id' => $mutationId,
            'status' => 'preparing',
            'current_cursor' => null,
            'cohort_cursor' => null,
            'snapshot_count' => 0,
            'chunk_count' => 0,
            'attempt_count' => 0,
            'command_payload' => $pins->commandCanonical(),
            'command_canonical' => $pins->commandCanonical(),
            'command_hash' => $pins->commandHash(),
            'policy_definition' => $pins->policyCanonical(),
            'policy_canonical' => $pins->policyCanonical(),
            'policy_hash' => $pins->policyHash(),
            'source_schema_version' => $pins->sourceSchemaVersion,
            'formula_version' => $pins->formulaVersion,
            'business_date' => $pins->businessDate,
            'captured_at' => $pins->capturedAt,
            'frozen_at' => null,
            'range_count' => 0,
            'source_row_count' => 0,
            'available_at' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error_code' => null,
            'started_at' => $pins->capturedAt,
            'completed_at' => null,
            'dead_lettered_at' => null,
        ]);

        return [$requestId, $pins];
    }

    private function snapshotForKey(array $fixture, WorkforceCapacityCohortKey $key): WorkforceCapacitySnapshot
    {
        return (new WorkforceCapacitySnapshotBuilder(WorkforceCapacityPolicyDefinition::v1('Europe/Moscow')))->build(
            key: $key,
            captureKind: 'change_capture',
            capturedAt: $this->utcNow(),
            actorUserId: null,
            serviceActor: 'workforce-owner',
            source: $fixture['source'],
        );
    }

    private function store(): EloquentWorkforceCapacitySnapshotStore
    {
        return new EloquentWorkforceCapacitySnapshotStore(new PostgresWorkforceCapacityCohortLock);
    }

    private function insertUnsealedSnapshot(WorkforceCapacitySnapshot $snapshot, string $mutationId): int
    {
        $payload = $snapshot->toPersistence();
        unset($payload['semantic_label']);
        $captureRequestId = $this->insertSyncCaptureRequest(
            $snapshot->key->organizationId,
            $mutationId,
            $snapshot->capturedAt,
        );

        return (int) DB::table('workforce_capacity_snapshots')->insertGetId([
            ...$payload,
            'capture_request_id' => $captureRequestId,
            'capture_mutation_id' => $mutationId,
            'capture_cursor' => hash('sha256', $mutationId),
            'sealed_at' => null,
        ]);
    }

    private function insertSyncCaptureRequest(
        int $organizationId,
        string $mutationId,
        DateTimeImmutable $startedAt,
    ): int {
        return (int) DB::table('workforce_capacity_capture_requests')->insertGetId([
            'organization_id' => $organizationId,
            'mutation_id' => $mutationId,
            'status' => 'processing',
            'current_cursor' => null,
            'cohort_cursor' => null,
            'snapshot_count' => 0,
            'chunk_count' => 0,
            'attempt_count' => 0,
            'range_count' => 0,
            'source_row_count' => 0,
            'started_at' => $startedAt,
        ]);
    }

    private function canonicalJson(array $value): string
    {
        $canonicalize = function (array $item) use (&$canonicalize): array {
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                if (is_array($nested)) {
                    $item[$key] = $canonicalize($nested);
                }
            }

            return $item;
        };

        return json_encode($canonicalize($value), JSON_THROW_ON_ERROR);
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction(static function () use ($operation): void {
                $operation();
            });
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('Expected PostgreSQL to reject the operation.');
    }

    private function assertSqlState(QueryException $exception, string $expected): void
    {
        self::assertSame($expected, (string) ($exception->errorInfo[0] ?? $exception->getCode()));
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

final class ProgressOnlyWorkforceCapacitySnapshotStore implements WorkforceCapacitySnapshotStore
{
    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void {
        $updated = DB::table('workforce_capacity_capture_requests')
            ->where('mutation_id', $mutationId)
            ->where('status', 'processing')
            ->where('current_cursor', $priorCursor)
            ->update([
                'current_cursor' => $cursor,
                'snapshot_count' => DB::raw('snapshot_count + '.count($snapshots)),
                'chunk_count' => DB::raw('chunk_count + 1'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('workforce_capacity_progress_fixture_failed');
        }
    }

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void {
        throw new RuntimeException('workforce_capacity_progress_fixture_must_not_complete');
    }
}

final class CrashingWorkforceCapacitySnapshotStore implements WorkforceCapacitySnapshotStore
{
    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void {
        throw new RuntimeException('workforce_capacity_snapshot_crash_fixture');
    }

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void {
        throw new RuntimeException('workforce_capacity_snapshot_crash_fixture');
    }
}
