<?php

declare(strict_types=1);

namespace Tests\Feature\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\CommitmentFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\EloquentLookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\EloquentScheduleRevisionSourceGuard;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessAbility;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessEventStateMachine;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessPolicyEvaluator;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessSnapshotFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleRevisionFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleSourceWatermark;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class LookaheadReadinessSourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    private bool $databaseSafetyApproved = false;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('LOOKAHEAD_READINESS_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set LOOKAHEAD_READINESS_POSTGRES_TESTS=1 to run isolated PostgreSQL lookahead-readiness tests.',
            );
        }
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }
        $databaseUrl = config('database.connections.pgsql.url');
        if (is_string($databaseUrl) && trim($databaseUrl) !== '') {
            $this->markTestSkipped('DB_URL must be empty for isolated lookahead-readiness tests.');
        }
        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        $this->databaseSafetyApproved = true;
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_postgres_guard_replays_events_for_forged_green_and_revoked_waiver(): void
    {
        $definition = DB::selectOne(
            "SELECT pg_get_functiondef('lookahead_readiness_expected_evaluation(bigint,bigint,bigint,timestamptz)'::regprocedure) AS source",
        );
        self::assertNotNull($definition);
        self::assertStringContainsString("event_type = 'waiver_approved'", (string) $definition->source);
        self::assertStringContainsString('aggregate_latest', (string) $definition->source);
        self::assertStringContainsString('component_outcomes', (string) $definition->source);
    }

    public function test_postgres_task_stream_lock_closes_event_snapshot_race_and_tail_branch(): void
    {
        $eventGuard = DB::selectOne(
            "SELECT pg_get_functiondef('lookahead_readiness_validate_event()'::regprocedure) AS source",
        );
        $snapshotGuard = DB::selectOne(
            "SELECT pg_get_functiondef('lookahead_readiness_validate_snapshot()'::regprocedure) AS source",
        );
        self::assertNotNull($eventGuard);
        self::assertNotNull($snapshotGuard);
        self::assertStringContainsString('lookahead-task-event-stream:', (string) $eventGuard->source);
        self::assertStringContainsString('lookahead-task-event-stream:', (string) $snapshotGuard->source);
        self::assertStringContainsString('prior event is not aggregate tail', (string) $eventGuard->source);
    }

    public function test_sealed_source_is_append_only_cross_lineage_safe_and_replays_after_operational_mutation(): void
    {
        $fixture = $this->sourceFixture();
        $event = $this->constraintEvent($fixture);
        $eventReceipt = $this->store()->transaction(fn () => $this->store()->appendEvent(
            $event,
            $this->decision($fixture, LookaheadReadinessAbility::MANAGE_CONSTRAINTS),
        ));
        $snapshotReceipt = $this->store()->transaction(fn () => $this->store()->materializeReadiness([
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'schedule_id' => $fixture['schedule_id'],
            'commitment_revision_id' => (int) $fixture['commitment_receipt']->entityId,
            'commitment_task_id' => $fixture['commitment_task_id'],
            'as_of_utc' => $event->occurredAtUtc(),
            'actor_id' => $fixture['actor_id'],
            'idempotency_key' => 'snapshot-task-a-v1',
        ], $this->decision($fixture, LookaheadReadinessAbility::SEAL_EVALUATION)));

        self::assertSame('blocked', DB::table('lookahead_readiness_snapshots')->value('state'));
        self::assertSame(1, DB::table('lookahead_readiness_snapshots')->count());
        $this->assertMutationRejected('lookahead_readiness_policy_versions', (int) $fixture['policy_receipt']->entityId);
        $this->assertMutationRejected('schedule_plan_revisions', (int) $fixture['schedule_receipt']->entityId);
        $this->assertMutationRejected('lookahead_commitment_revisions', (int) $fixture['commitment_receipt']->entityId);
        $this->assertMutationRejected('lookahead_readiness_snapshots', (int) $snapshotReceipt->entityId);

        $crossProject = Project::factory()->create(['organization_id' => $fixture['organization_id']]);
        $exception = $this->captureQueryException(function () use ($event, $fixture, $crossProject): void {
            $attributes = $this->eventAttributes($event, $fixture);
            $attributes['event_id'] = '018f6f5a-4ca2-7a11-bf61-0242ac120003';
            $attributes['idempotency_key'] = 'constraint-900-cross-project';
            $attributes['project_id'] = $crossProject->id;
            DB::table('lookahead_readiness_events')->insert($attributes);
        });
        self::assertContains($exception->errorInfo[0] ?? null, ['23503', '23514']);

        $sealedHash = DB::table('lookahead_readiness_snapshots')->value('snapshot_hash');
        DB::table('schedule_tasks')->where('id', $fixture['source_task_id'])->update([
            'name' => 'Mutated current task',
            'deleted_at' => now(),
        ]);
        self::assertSame(
            $sealedHash,
            DB::table('lookahead_readiness_snapshots')->value('snapshot_hash'),
        );
        self::assertSame(
            'Task A',
            DB::table('schedule_plan_revision_tasks')->value('task_name'),
        );
    }

    public function test_exact_retry_conflict_and_failed_transaction_are_atomic(): void
    {
        $fixture = $this->identityFixture();
        $draft = $this->scheduleDraft($fixture);
        $store = $this->store();

        $first = $store->transaction(fn () => $store->approveScheduleRevision(
            $draft,
            (new ScheduleRevisionFactory)->contentHash($draft),
            $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
            '2026-08-05T05:00:00.000000Z',
            'schedule-approved-v1',
        ));
        $replay = $store->transaction(fn () => $store->approveScheduleRevision(
            $draft,
            (new ScheduleRevisionFactory)->contentHash($draft),
            $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
            '2026-08-05T05:00:00.000000Z',
            'schedule-approved-v1',
        ));
        self::assertSame($first->entityId, $replay->entityId);
        self::assertTrue($replay->replay);

        $changed = $this->scheduleDraftArray($fixture);
        $changed['tasks'][0]['planned_work_hours'] = '17.0000';
        $changedDraft = ScheduleRevisionDraft::fromArray($changed);
        try {
            $store->transaction(fn () => $store->approveScheduleRevision(
                $changedDraft,
                (new ScheduleRevisionFactory)->contentHash($changedDraft),
                $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
                '2026-08-05T05:00:00.000000Z',
                'schedule-approved-v1',
            ));
            self::fail('Conflicting replay must fail.');
        } catch (LogicException $exception) {
            self::assertSame('lookahead_readiness_idempotency_conflict', $exception->getMessage());
        }

        try {
            $store->transaction(function () use ($store, $draft, $fixture): void {
                $store->approveScheduleRevision(
                    $draft,
                    (new ScheduleRevisionFactory)->contentHash($draft),
                    $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
                    '2026-08-06T05:00:00.000000Z',
                    'schedule-approved-rollback',
                );
                throw new RuntimeException('force_rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('force_rollback', $exception->getMessage());
        }
        self::assertSame(1, DB::table('schedule_plan_revisions')->count());
        self::assertSame(1, DB::table('schedule_plan_revision_tasks')->count());
    }

    public function test_approval_rejects_caller_supplied_snapshot_after_the_operational_source_changes(): void
    {
        $fixture = $this->identityFixture();
        $draft = $this->scheduleDraft($fixture);
        DB::table('schedule_tasks')->where('id', $fixture['source_task_id'])->update([
            'planned_work_hours' => 18,
            'updated_at' => now()->addSecond(),
        ]);

        try {
            $this->store()->transaction(fn () => $this->store()->approveScheduleRevision(
                $draft,
                (new ScheduleRevisionFactory)->contentHash($draft),
                $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
                '2026-08-05T05:00:00.000000Z',
                'stale-schedule-approval',
            ));
            self::fail('Stale schedule snapshot must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('lookahead_readiness_stale_schedule_source', $exception->getMessage());
        }

        self::assertSame(0, DB::table('schedule_plan_revisions')->count());
    }

    public function test_approval_rejects_fabricated_planning_facts_even_with_the_current_source_watermark(): void
    {
        $fixture = $this->identityFixture();
        $data = $this->scheduleDraftArray($fixture);
        $data['tasks'][0]['planned_work_hours'] = '999.0000';
        $draft = ScheduleRevisionDraft::fromArray($data);

        try {
            $this->store()->transaction(fn () => $this->store()->approveScheduleRevision(
                $draft,
                (new ScheduleRevisionFactory)->contentHash($draft),
                $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
                '2026-08-05T05:00:00.000000Z',
                'fabricated-schedule-approval',
            ));
            self::fail('Caller-supplied planning facts must match the locked operational source.');
        } catch (LogicException $exception) {
            self::assertSame('lookahead_readiness_schedule_snapshot_mismatch', $exception->getMessage());
        }

        self::assertSame(0, DB::table('schedule_plan_revisions')->count());
    }

    public function test_database_rejects_forged_waiver_permission_snapshot_and_accepts_owner_validated_waiver(): void
    {
        $fixture = $this->sourceFixture();
        $request = ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120010',
            'idempotency_key' => 'waiver-900-requested-v1',
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'schedule_id' => $fixture['schedule_id'],
            'commitment_revision_id' => (int) $fixture['commitment_receipt']->entityId,
            'commitment_task_id' => $fixture['commitment_task_id'],
            'event_type' => ReadinessEventType::WAIVER_REQUESTED->value,
            'occurred_at' => '2026-08-05T09:00:00+03:00',
            'actor_id' => $fixture['actor_id'],
            'aggregate_id' => 'waiver:900',
            'payload' => ['category' => 'permit', 'reason' => 'Awaiting authority response'],
            'evidence' => null,
            'prior_event_id' => null,
        ], $fixture['policy']);
        $this->store()->transaction(fn () => $this->store()->appendEvent(
            $request,
            $this->decision($fixture, LookaheadReadinessAbility::MANAGE_CONSTRAINTS),
        ));
        $approved = ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120011',
            'idempotency_key' => 'waiver-900-approved-v1',
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'schedule_id' => $fixture['schedule_id'],
            'commitment_revision_id' => (int) $fixture['commitment_receipt']->entityId,
            'commitment_task_id' => $fixture['commitment_task_id'],
            'event_type' => ReadinessEventType::WAIVER_APPROVED->value,
            'occurred_at' => '2026-08-05T10:00:00+03:00',
            'actor_id' => $fixture['actor_id'],
            'aggregate_id' => 'waiver:900',
            'payload' => [
                'category' => 'permit',
                'reason' => 'Authority response documented',
                'approver_permission' => 'schedule.readiness.waivers.approve',
                'valid_until' => '2026-08-07T10:00:00+03:00',
                'schedule_revision_hash' => $fixture['schedule_receipt']->contentHash,
            ],
            'evidence' => [
                'type' => 'document',
                'locator' => "org-{$fixture['organization_id']}/readiness/waiver-900",
                'version' => 'v1',
                'hash' => str_repeat('f', 64),
            ],
            'prior_event_id' => $request->eventId,
        ], $fixture['policy']);

        $forged = $this->eventAttributes($approved, $fixture);
        $forged['event_id'] = '018f6f5a-4ca2-7a11-bf61-0242ac120012';
        $forged['idempotency_key'] = 'waiver-900-forged-v1';
        $payload = $approved->payload;
        $payload['approver_permission'] = 'schedule.view';
        $forged['payload'] = LookaheadReadinessCanonicalJson::encode($payload);
        $forged['payload_hash'] = LookaheadReadinessCanonicalJson::hash($payload);
        $forgedAuthorization = $this->decodeJson($forged['authorization_decision']);
        $forgedAuthorization['permission'] = 'schedule.view';
        $forged['authorization_decision'] = LookaheadReadinessCanonicalJson::encode($forgedAuthorization);
        $forged['evidence_hash'] = LookaheadReadinessCanonicalJson::hash([
            'actor_id' => (string) $approved->actorId,
            'aggregate_id' => $approved->aggregateId,
            'commitment_revision_id' => (string) $approved->commitmentRevisionId,
            'commitment_task_id' => (string) $approved->commitmentTaskId,
            'event_id' => $forged['event_id'],
            'event_type' => $approved->eventType->value,
            'idempotency_key' => $forged['idempotency_key'],
            'organization_id' => (string) $approved->organizationId,
            'occurred_at_utc' => $approved->occurredAtUtc(),
            'payload_hash' => $forged['payload_hash'],
            'policy_hash' => $approved->policy->hash(),
            'project_id' => (string) $approved->projectId,
            'schedule_id' => (string) $approved->scheduleId,
            'evidence' => $approved->evidence,
            'prior_event_id' => $approved->priorEventId,
        ]);
        $exception = $this->captureQueryException(
            static fn () => DB::table('lookahead_readiness_events')->insert($forged),
        );
        self::assertSame('23514', $exception->errorInfo[0] ?? null);

        $receipt = $this->store()->transaction(fn () => $this->store()->appendEvent(
            $approved,
            $this->decision($fixture, LookaheadReadinessAbility::APPROVE_WAIVER),
        ));
        self::assertSame($approved->eventId, $receipt->entityId);
    }

    public function test_event_idempotency_conflict_checks_the_complete_audit_lineage(): void
    {
        $fixture = $this->sourceFixture();
        $event = $this->constraintEvent($fixture);
        $store = $this->store();
        $store->transaction(fn () => $store->appendEvent(
            $event,
            $this->decision($fixture, LookaheadReadinessAbility::MANAGE_CONSTRAINTS),
        ));
        $changed = ReadinessEvent::fromArray([
            'event_id' => $event->eventId,
            'idempotency_key' => $event->idempotencyKey,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'schedule_id' => $event->scheduleId,
            'commitment_revision_id' => $event->commitmentRevisionId,
            'commitment_task_id' => $event->commitmentTaskId,
            'event_type' => $event->eventType->value,
            'occurred_at' => $event->occurredAtUtc(),
            'actor_id' => $event->actorId,
            'aggregate_id' => $event->aggregateId,
            'payload' => [...$event->payload, 'owner_ref' => 'user:changed'],
            'evidence' => $event->evidence,
            'prior_event_id' => $event->priorEventId,
        ], $event->policy);

        try {
            $store->transaction(fn () => $store->appendEvent(
                $changed,
                $this->decision($fixture, LookaheadReadinessAbility::MANAGE_CONSTRAINTS),
            ));
            self::fail('Same idempotency key with changed audit lineage must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('lookahead_readiness_idempotency_conflict', $exception->getMessage());
        }

        self::assertSame(1, DB::table('lookahead_readiness_events')->count());
    }

    public function test_concurrent_approval_allocates_unique_monotonic_revisions(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            if (getenv('CI') === 'true') {
                self::fail('CI lookahead-readiness race gate requires pcntl.');
            }
            $this->markTestSkipped('Requires pcntl for a real PostgreSQL process race.');
        }

        $fixture = $this->identityFixture();
        $draft = $this->scheduleDraft($fixture);
        $this->store()->transaction(fn () => $this->store()->approveScheduleRevision(
            $draft,
            (new ScheduleRevisionFactory)->contentHash($draft),
            $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
            '2026-08-05T05:00:00.000000Z',
            'schedule-race-base',
        ));

        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'lookahead-readiness-race-'.bin2hex(random_bytes(8)),
        );
        $names = ['lookahead_race_a_'.bin2hex(random_bytes(3)), 'lookahead_race_b_'.bin2hex(random_bytes(3))];
        $original = (string) config('database.default');
        $children = [];

        try {
            foreach ($names as $index => $name) {
                $harness->independentConnection($name);
                DB::setDefaultConnection($name);
                $workerId = $index + 1;
                $children[] = $harness->spawn($workerId, function () use ($draft, $fixture, $workerId): array {
                    $store = $this->store();
                    $receipt = $store->transaction(fn () => $store->approveScheduleRevision(
                        $draft,
                        (new ScheduleRevisionFactory)->contentHash($draft),
                        $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
                        '2026-08-0'.($workerId + 5).'T05:00:00.000000Z',
                        "schedule-race-{$workerId}",
                    ));

                    return ['revision' => $receipt->revisionNumber];
                });
            }
            DB::setDefaultConnection($original);
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);

            $revisions = [
                (int) $harness->result(1)['revision'],
                (int) $harness->result(2)['revision'],
            ];
            sort($revisions);
            self::assertSame([2, 3], $revisions);
            self::assertSame([1, 2, 3], DB::table('schedule_plan_revisions')
                ->orderBy('revision_number')
                ->pluck('revision_number')
                ->map(static fn ($value): int => (int) $value)
                ->all());
        } finally {
            DB::setDefaultConnection($original);
            $failure = null;
            $harness->cleanupStep(static fn () => $harness->terminateAndReap($children), $failure);
            foreach ($names as $name) {
                $harness->cleanupStep(static fn () => DB::purge($name), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->cleanup(), $failure);
            if ($failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    public function test_concurrent_exact_event_retry_produces_one_immutable_event(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            if (getenv('CI') === 'true') {
                self::fail('CI lookahead-readiness event race gate requires pcntl.');
            }
            $this->markTestSkipped('Requires pcntl for a real PostgreSQL process race.');
        }

        $fixture = $this->sourceFixture();
        $event = $this->constraintEvent($fixture);
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'lookahead-event-race-'.bin2hex(random_bytes(8)),
        );
        $names = ['lookahead_event_a_'.bin2hex(random_bytes(3)), 'lookahead_event_b_'.bin2hex(random_bytes(3))];
        $original = (string) config('database.default');
        $children = [];

        try {
            foreach ($names as $index => $name) {
                $harness->independentConnection($name);
                DB::setDefaultConnection($name);
                $workerId = $index + 1;
                $children[] = $harness->spawn($workerId, function () use ($event, $fixture): array {
                    $store = $this->store();
                    $receipt = $store->transaction(fn () => $store->appendEvent(
                        $event,
                        $this->decision($fixture, LookaheadReadinessAbility::MANAGE_CONSTRAINTS),
                    ));

                    return ['event_id' => $receipt->entityId, 'replay' => $receipt->replay];
                });
            }
            DB::setDefaultConnection($original);
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);

            self::assertSame($event->eventId, $harness->result(1)['event_id']);
            self::assertSame($event->eventId, $harness->result(2)['event_id']);
            self::assertSame(1, DB::table('lookahead_readiness_events')
                ->where('idempotency_key', $event->idempotencyKey)
                ->count());
        } finally {
            DB::setDefaultConnection($original);
            $failure = null;
            $harness->cleanupStep(static fn () => $harness->terminateAndReap($children), $failure);
            foreach ($names as $name) {
                $harness->cleanupStep(static fn () => DB::purge($name), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->cleanup(), $failure);
            if ($failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    public function test_snapshot_as_of_cursor_query_uses_the_covering_index(): void
    {
        $fixture = $this->sourceFixture();
        DB::transaction(function () use ($fixture): void {
            DB::statement('SET LOCAL enable_seqscan = off');
            $plan = DB::select(
                'EXPLAIN (FORMAT TEXT) SELECT id FROM lookahead_readiness_snapshots '
                .'WHERE organization_id = ? AND project_id = ? AND schedule_id = ? AND calculated_at <= ? '
                .'ORDER BY calculated_at DESC, id DESC LIMIT 50',
                [
                    $fixture['organization_id'],
                    $fixture['project_id'],
                    $fixture['schedule_id'],
                    '2026-08-10T00:00:00+00:00',
                ],
            );
            $text = implode("\n", array_map(static fn ($row): string => (string) $row->{'QUERY PLAN'}, $plan));
            self::assertStringContainsString('lookahead_snapshot_as_of_idx', $text);
        });
    }

    private function sourceFixture(): array
    {
        $fixture = $this->identityFixture();
        $store = $this->store();
        $policy = ReadinessPolicyDefinition::v1($fixture['organization_id']);
        $policyReceipt = $store->transaction(
            fn () => $store->publishPolicy(
                $policy,
                $this->decision($fixture, LookaheadReadinessAbility::PUBLISH_POLICY, 0),
                'policy-v1',
            ),
        );
        $scheduleDraft = $this->scheduleDraft($fixture);
        $scheduleReceipt = $store->transaction(fn () => $store->approveScheduleRevision(
            $scheduleDraft,
            (new ScheduleRevisionFactory)->contentHash($scheduleDraft),
            $this->decision($fixture, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'schedule-approved-v1',
        ));
        $commitment = (new CommitmentFactory)->publish(
            CommitmentDraft::fromArray([
                'organization_id' => $fixture['organization_id'],
                'project_id' => $fixture['project_id'],
                'schedule_id' => $fixture['schedule_id'],
                'window_start' => '2026-08-10',
                'window_end' => '2026-08-16',
                'planning_timezone' => 'Europe/Moscow',
                'tasks' => [[
                    'schedule_task_external_id' => 'task-a',
                    'committed_start' => '2026-08-10',
                    'committed_end' => '2026-08-11',
                    'planned_quantity' => '2.0000',
                    'planned_work_hours' => '16.0000',
                    'responsible_role' => 'site_manager',
                    'responsible_user_id' => $fixture['actor_id'],
                    'inclusion_reason' => 'starts_in_window',
                ]],
            ]),
            $scheduleDraft,
            $scheduleReceipt->contentHash,
            $policy,
            $fixture['actor_id'],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $commitmentReceipt = $store->transaction(fn () => $store->publishCommitment(
            $commitment,
            (int) $scheduleReceipt->entityId,
            (int) $policyReceipt->entityId,
            $this->decision($fixture, LookaheadReadinessAbility::PUBLISH_COMMITMENT),
            'commitment-v1',
        ));

        return [
            ...$fixture,
            'policy' => $policy,
            'policy_receipt' => $policyReceipt,
            'schedule_draft' => $scheduleDraft,
            'schedule_receipt' => $scheduleReceipt,
            'commitment_receipt' => $commitmentReceipt,
            'commitment_task_id' => (int) DB::table('lookahead_commitment_tasks')->value('id'),
            'commitment_published_at' => $commitment->publishedAtUtc,
        ];
    }

    private function identityFixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'is_active' => true,
            'is_owner' => false,
            'project_access_mode' => 'all_projects',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $systemContext = AuthorizationContext::getSystemContext();
        AuthorizationContext::getOrganizationContext((int) $organization->id);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $organization->id);
        $assignment = UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_slug' => 'super_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => $systemContext->id,
            'assigned_by' => $actor->id,
            'expires_at' => null,
            'is_active' => true,
        ]);
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'name' => 'R07 test schedule',
            'status' => 'active',
            'timezone' => 'Europe/Moscow',
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $actor->id,
            'name' => 'Task A',
            'wbs_code' => '1.1',
            'task_type' => 'task',
            'planned_start_date' => '2026-08-10',
            'planned_end_date' => '2026-08-11',
            'planned_duration_days' => 2,
            'planned_work_hours' => 16,
            'quantity' => 2,
            'completed_quantity' => 0,
            'is_critical' => true,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'level' => 0,
            'sort_order' => 1,
        ]);

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'actor_id' => (int) $actor->id,
            'schedule_id' => (int) $schedule->id,
            'source_task_id' => (int) $task->id,
            'assignment_id' => (int) $assignment->id,
            'system_context_id' => (int) $systemContext->id,
        ];
    }

    private function scheduleDraft(array $fixture): ScheduleRevisionDraft
    {
        return ScheduleRevisionDraft::fromArray($this->scheduleDraftArray($fixture));
    }

    private function scheduleDraftArray(array $fixture): array
    {
        return [
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'schedule_id' => $fixture['schedule_id'],
            'planning_timezone' => 'Europe/Moscow',
            'calendar' => [
                'calendar_id' => 'calendar-2026-v1',
                'calendar_hash' => str_repeat('c', 64),
                'working_weekdays' => [1, 2, 3, 4, 5],
            ],
            'expected_source_watermark' => $this->currentSourceWatermark($fixture),
            'observed_source_watermark' => $this->currentSourceWatermark($fixture),
            'tasks' => [[
                'external_id' => 'task-a',
                'source_task_id' => $fixture['source_task_id'],
                'wbs_code' => '1.1',
                'name' => 'Task A',
                'task_class' => 'standard',
                'planned_start' => '2026-08-10',
                'planned_end' => '2026-08-11',
                'duration_minutes' => 960,
                'planned_quantity' => '2.0000',
                'planned_work_hours' => '16.0000',
                'critical' => true,
                'constraint_point' => 'finish',
                'parent_external_id' => null,
            ]],
            'dependencies' => [],
        ];
    }

    private function constraintEvent(array $fixture): ReadinessEvent
    {
        return ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'constraint-900-created-v1',
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'schedule_id' => $fixture['schedule_id'],
            'commitment_revision_id' => (int) $fixture['commitment_receipt']->entityId,
            'commitment_task_id' => $fixture['commitment_task_id'],
            'event_type' => ReadinessEventType::CONSTRAINT_REGISTERED->value,
            'occurred_at' => $fixture['commitment_published_at'],
            'actor_id' => $fixture['actor_id'],
            'aggregate_id' => 'constraint:900',
            'payload' => [
                'category' => 'permit',
                'severity' => 'hard',
                'owner_ref' => "user:{$fixture['actor_id']}",
                'due_at' => '2026-08-09T18:00:00+03:00',
            ],
            'evidence' => [
                'type' => 'document',
                'locator' => "org-{$fixture['organization_id']}/readiness/permit-900",
                'version' => 'v1',
                'hash' => str_repeat('e', 64),
            ],
            'prior_event_id' => null,
        ], $fixture['policy']);
    }

    private function eventAttributes(ReadinessEvent $event, array $fixture): array
    {
        return [
            'event_id' => $event->eventId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'schedule_id' => $event->scheduleId,
            'commitment_revision_id' => $event->commitmentRevisionId,
            'commitment_task_id' => $event->commitmentTaskId,
            'readiness_policy_version_id' => $fixture['policy_receipt']->entityId,
            'event_type' => $event->eventType->value,
            'idempotency_key' => $event->idempotencyKey,
            'occurred_at' => $event->occurredAtUtc(),
            'actor_id' => $event->actorId,
            'aggregate_id' => $event->aggregateId,
            'payload' => LookaheadReadinessCanonicalJson::encode($event->payload),
            'payload_hash' => $event->payloadHash(),
            'evidence' => LookaheadReadinessCanonicalJson::encode($event->evidence ?? []),
            'evidence_hash' => $event->evidenceHash(),
            'prior_event_id' => $event->priorEventId,
            'policy_hash' => $event->policy->hash(),
            'schedule_revision_hash' => $fixture['schedule_receipt']->contentHash,
            ...$this->authorizationColumns($this->decision(
                $fixture,
                match ($event->eventType) {
                    ReadinessEventType::WAIVER_APPROVED,
                    ReadinessEventType::WAIVER_REJECTED,
                    ReadinessEventType::WAIVER_EXPIRED,
                    ReadinessEventType::WAIVER_REVOKED => LookaheadReadinessAbility::APPROVE_WAIVER,
                    ReadinessEventType::READINESS_EVALUATED => LookaheadReadinessAbility::SEAL_EVALUATION,
                    default => LookaheadReadinessAbility::MANAGE_CONSTRAINTS,
                },
            )),
            'created_at' => $event->occurredAtUtc(),
        ];
    }

    private function authorizationColumns(AuthorizationDecision $decision): array
    {
        return [
            'authorization_decision' => LookaheadReadinessCanonicalJson::encode($decision->canonicalSnapshot()),
            'authorization_decision_hash' => $decision->decisionHash,
        ];
    }

    private function satisfied(string $category): array
    {
        return [
            'category' => $category,
            'outcome' => 'satisfied',
            'evidence_type' => 'document',
            'evidence_hash' => hash('sha256', $category),
        ];
    }

    private function store(): EloquentLookaheadReadinessSourceStore
    {
        return new EloquentLookaheadReadinessSourceStore(
            new ScheduleRevisionFactory,
            new EloquentScheduleRevisionSourceGuard(new ScheduleSourceWatermark),
            new ReadinessEventStateMachine,
            new ReadinessPolicyEvaluator,
            new ReadinessSnapshotFactory,
            new RoleScanner,
        );
    }

    private function decision(array $fixture, string $permission, ?int $projectId = null): AuthorizationDecision
    {
        $projectId ??= $fixture['project_id'];
        $roleDefinition = $this->decodeJson(DB::table('lookahead_readiness_system_role_definitions')
            ->where('role_slug', 'super_admin')
            ->value('canonical_definition'));
        $assignment = DB::table('user_role_assignments')->where('id', $fixture['assignment_id'])->first();
        $membership = DB::table('organization_user')
            ->where('organization_id', $fixture['organization_id'])
            ->where('user_id', $fixture['actor_id'])
            ->first();
        self::assertNotNull($assignment);
        self::assertNotNull($membership);
        $grants = [[
            'assignment_id' => (string) $assignment->id,
            'context_id' => (string) $assignment->context_id,
            'matched_permission' => '*',
            'role_definition' => $roleDefinition,
            'role_definition_hash' => LookaheadReadinessCanonicalJson::hash($roleDefinition),
            'role_slug' => 'super_admin',
            'role_type' => 'system',
            'assignment_updated_at' => (string) $assignment->updated_at,
            'conditions_hash' => LookaheadReadinessCanonicalJson::hash([]),
        ]];
        $contextFactors = [
            'organization_membership' => [
                'organization_id' => (string) $fixture['organization_id'],
                'project_access_mode' => 'all_projects',
                'updated_at' => (string) $membership->updated_at,
            ],
            'project_membership' => null,
            'context_ids' => [(string) $fixture['system_context_id']],
        ];

        return new AuthorizationDecision(
            $fixture['actor_id'],
            $permission,
            $fixture['organization_id'],
            $projectId,
            LookaheadReadinessCanonicalJson::hash($grants),
            LookaheadReadinessCanonicalJson::hash([
                'context_factors' => $contextFactors,
                'granting_assignments' => $grants,
                'permission' => $permission,
            ]),
            ['super_admin'],
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            $contextFactors,
            $grants,
        );
    }

    private function decodeJson(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function currentSourceWatermark(array $fixture): string
    {
        return (new EloquentScheduleRevisionSourceGuard(new ScheduleSourceWatermark))->current(
            $fixture['organization_id'],
            $fixture['project_id'],
            $fixture['schedule_id'],
        );
    }

    private function assertMutationRejected(string $table, int $id): void
    {
        $update = $this->captureQueryException(
            static fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()]),
        );
        self::assertSame('55000', $update->errorInfo[0] ?? null);
        $delete = $this->captureQueryException(
            static fn () => DB::table($table)->where('id', $id)->delete(),
        );
        self::assertSame('55000', $delete->errorInfo[0] ?? null);
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('The PostgreSQL contract was expected to reject the operation.');
    }
}
