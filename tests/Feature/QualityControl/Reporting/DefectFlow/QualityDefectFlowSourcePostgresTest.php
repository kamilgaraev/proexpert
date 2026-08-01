<?php

declare(strict_types=1);

namespace Tests\Feature\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowOwnerEventSink;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\EloquentQualityDefectFlowStore;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowCanonicalJson;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowIdempotencyGuard;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowOwnerEventFactory;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class QualityDefectFlowSourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    private bool $databaseSafetyApproved = false;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('QUALITY_DEFECT_FLOW_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set QUALITY_DEFECT_FLOW_POSTGRES_TESTS=1 to run isolated PostgreSQL quality-defect-flow tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $databaseUrl = config('database.connections.pgsql.url');
        if (is_string($databaseUrl) && trim($databaseUrl) !== '') {
            $this->markTestSkipped('DB_URL must be empty for isolated quality-defect-flow tests.');
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
        if (! $this->databaseSafetyApproved) {
            return;
        }

        parent::tearDown();
    }

    public function test_all_owner_writers_emit_atomic_canonical_events_including_handover(): void
    {
        [$organization, $project, $actor, $assignee] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);

        $defect = $service->create((int) $organization->id, (int) $actor->id, $this->createData($project));
        $defect = $service->assign($defect, (int) $assignee->id, (int) $actor->id);
        $defect = $service->start($defect, (int) $actor->id);
        $defect = $service->resolve($defect, (int) $actor->id, ['comment' => 'Evidence remains outside R23']);
        $defect = $service->verify($defect, (int) $actor->id, false);
        $defect = $service->start($defect, (int) $actor->id);
        $defect = $service->resolve($defect, (int) $actor->id, ['comment' => 'Second review']);
        $service->verify($defect, (int) $actor->id, true);

        $rejected = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Reject path'),
        );
        $service->reject($rejected, (int) $actor->id, 'Rejected by owner');

        $cancelled = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Cancel path'),
        );
        $service->cancel($cancelled, (int) $actor->id, 'Cancelled by owner');

        $scope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'title' => 'Acceptance scope',
            'status' => 'in_progress',
        ]);
        $session = AcceptanceSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $actor->id,
            'status' => 'in_progress',
            'participant_user_ids' => [],
        ]);
        $finding = $this->app->make(HandoverAcceptanceService::class)->addFinding(
            $session,
            (int) $actor->id,
            [
                'create_quality_defect' => true,
                'title' => 'Acceptance finding',
                'description' => 'Must not enter evidence payload',
                'severity' => 'major',
                'quality_defect_inspection_required' => true,
            ],
        );

        self::assertNotNull($finding->quality_defect_id);
        self::assertSame(4, DB::table('quality_defect_flow_events')->where('event_kind', 'created')->count());
        foreach ([
            'assigned',
            'started',
            'submitted_for_review',
            'verified_resolved',
            'returned_for_rework',
            'rejected',
            'cancelled',
        ] as $eventKind) {
            self::assertGreaterThanOrEqual(
                1,
                DB::table('quality_defect_flow_events')->where('event_kind', $eventKind)->count(),
                $eventKind,
            );
        }
        $acceptanceEvent = DB::table('quality_defect_flow_events')
            ->where('quality_defect_id', $finding->quality_defect_id)
            ->first();
        self::assertSame((int) $scope->id, (int) $acceptanceEvent?->acceptance_scope_id);
        self::assertSame((int) $session->id, (int) $acceptanceEvent?->acceptance_session_id);
        self::assertStringNotContainsString('Must not enter evidence payload', (string) $acceptanceEvent?->business_snapshot);
    }

    public function test_exact_replay_returns_same_event_and_conflict_fails_closed(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $defect = $this->app->make(QualityDefectService::class)->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project),
        );
        $event = $this->eventForLatestHistory($defect, QualityDefectFlowEventKind::CREATED);
        $store = $this->store();

        $first = DB::transaction(fn (): string => $store->append($event));
        $second = DB::transaction(fn (): string => $store->append($event));

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('quality_defect_flow_events')
            ->where('quality_defect_id', $defect->id)
            ->where('event_kind', 'created')
            ->count());

        $conflict = new QualityDefectFlowEvent(
            eventKind: $event->eventKind,
            fromStatus: $event->fromStatus,
            toStatus: $event->toStatus,
            actorId: $event->actorId,
            occurredAt: $event->occurredAt->modify('+1 second'),
            snapshot: $event->snapshot,
            sourceIdentity: $event->sourceIdentity,
            policy: $event->policy,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quality_defect_flow_idempotency_conflict');
        DB::transaction(fn (): string => $store->append($conflict));
    }

    public function test_database_denies_event_policy_and_gap_mutation(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);
        $defect = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project),
        );
        $legacy = $this->legacyDefect($organization, $project, $actor, QualityDefectStatusEnum::ASSIGNED);
        DB::transaction(fn (): string => $this->store()->append(
            $this->eventForLatestHistory($legacy, QualityDefectFlowEventKind::ASSIGNED),
        ));

        $eventId = (string) DB::table('quality_defect_flow_events')->where('quality_defect_id', $defect->id)->value('event_id');
        $policyId = (int) DB::table('quality_defect_flow_policies')->value('id');
        $gapId = (string) DB::table('quality_defect_flow_gaps')->value('gap_id');

        $this->assertSqlState($this->captureQueryException(static function () use ($eventId): void {
            DB::table('quality_defect_flow_events')->where('event_id', $eventId)->update(['source_hash' => str_repeat('f', 64)]);
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($policyId): void {
            DB::table('quality_defect_flow_policies')->where('id', $policyId)->delete();
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($gapId): void {
            DB::table('quality_defect_flow_gaps')->where('gap_id', $gapId)->delete();
        }), '55000');
    }

    public function test_legacy_transition_is_quarantined_without_current_project_reconstruction(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $legacy = $this->legacyDefect($organization, $project, $actor, QualityDefectStatusEnum::ASSIGNED);
        $event = $this->eventForLatestHistory($legacy, QualityDefectFlowEventKind::ASSIGNED);

        $gapId = DB::transaction(fn (): string => $this->store()->append($event));
        $gap = DB::table('quality_defect_flow_gaps')->where('gap_id', $gapId)->first();

        self::assertSame('source_contract_missing', $gap?->gap_code);
        self::assertNull($gap?->project_id);
        self::assertSame(0, DB::table('quality_defect_flow_events')->where('quality_defect_id', $legacy->id)->count());

        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $legacy->update(['project_id' => $otherProject->id]);
        $replayed = $this->eventForLatestHistory($legacy, QualityDefectFlowEventKind::ASSIGNED);
        $replayedGapId = DB::transaction(fn (): string => $this->store()->append($replayed));

        self::assertSame($gapId, $replayedGapId);
        self::assertNull(DB::table('quality_defect_flow_gaps')->where('gap_id', $gapId)->value('project_id'));

        $legacy->update(['status' => QualityDefectStatusEnum::OPEN]);
        $createdHistory = $legacy->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => QualityDefectStatusEnum::OPEN,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:31:00.123456',
        ]);
        $currentCardReconstruction = (new QualityDefectFlowOwnerEventFactory)->make(
            $legacy,
            $createdHistory,
            QualityDefectFlowEventKind::CREATED,
        );

        $this->assertSqlState($this->captureQueryException(
            fn (): string => $this->store()->append($currentCardReconstruction),
        ), '23514');
    }

    public function test_owner_mutation_rolls_back_when_recorder_fails(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $this->app->instance(QualityDefectFlowOwnerEventSink::class, new FailingQualityDefectFlowOwnerSink);
        $this->app->forgetInstance(QualityDefectService::class);
        $service = $this->app->make(QualityDefectService::class);

        try {
            $service->create(
                (int) $organization->id,
                (int) $actor->id,
                $this->createData($project),
            );
            self::fail('Recorder failure must abort the complete owner transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('quality_defect_flow_recorder_failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('quality_defects')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('quality_defect_status_history')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('quality_defect_flow_events')->where('organization_id', $organization->id)->count());
    }

    public function test_shared_transition_rolls_back_when_recorder_fails(): void
    {
        [$organization, $project, $actor, $assignee] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);
        $defect = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project),
        );

        $this->app->instance(QualityDefectFlowOwnerEventSink::class, new FailingQualityDefectFlowOwnerSink);
        $this->app->forgetInstance(QualityDefectService::class);

        try {
            $this->app->make(QualityDefectService::class)->assign(
                $defect,
                (int) $assignee->id,
                (int) $actor->id,
            );
            self::fail('Recorder failure must abort the shared transition transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('quality_defect_flow_recorder_failure', $exception->getMessage());
        }

        self::assertSame(QualityDefectStatusEnum::OPEN->value, QualityDefect::query()
            ->whereKey($defect->id)
            ->value('status'));
        self::assertSame(1, DB::table('quality_defect_status_history')
            ->where('quality_defect_id', $defect->id)
            ->count());
        self::assertSame(1, DB::table('quality_defect_flow_events')
            ->where('quality_defect_id', $defect->id)
            ->count());
    }

    public function test_handover_finding_rolls_back_when_canonical_recorder_fails(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'title' => 'Rollback scope',
            'status' => 'in_progress',
        ]);
        $session = AcceptanceSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $actor->id,
            'status' => 'in_progress',
            'participant_user_ids' => [],
        ]);
        $this->app->instance(QualityDefectFlowOwnerEventSink::class, new FailingQualityDefectFlowOwnerSink);
        $this->app->forgetInstance(QualityDefectService::class);
        $this->app->forgetInstance(HandoverAcceptanceService::class);

        try {
            $this->app->make(HandoverAcceptanceService::class)->addFinding(
                $session,
                (int) $actor->id,
                [
                    'create_quality_defect' => true,
                    'title' => 'Rollback finding',
                    'severity' => 'major',
                    'quality_defect_inspection_required' => true,
                ],
            );
            self::fail('Recorder failure must abort the nested handover transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('quality_defect_flow_recorder_failure', $exception->getMessage());
        }

        self::assertSame(0, AcceptanceFinding::query()->where('acceptance_session_id', $session->id)->count());
        self::assertSame(0, QualityDefect::query()->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('quality_defect_status_history')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('quality_defect_flow_events')->where('organization_id', $organization->id)->count());
    }

    public function test_same_timestamp_uses_event_id_tie_breaker_and_time_inversion_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-01 09:30:00.123456+00:00');
        try {
            [$organization, $project, $actor, $assignee] = $this->identityFixture();
            $service = $this->app->make(QualityDefectService::class);
            $defect = $service->create(
                (int) $organization->id,
                (int) $actor->id,
                $this->createData($project),
            );
            $service->assign($defect, (int) $assignee->id, (int) $actor->id);
        } finally {
            Carbon::setTestNow();
        }

        $events = DB::table('quality_defect_flow_events')
            ->where('quality_defect_id', $defect->id)
            ->orderBy('sequence_no')
            ->get();
        self::assertCount(2, $events);
        self::assertSame((string) $events[0]->occurred_at_utc, (string) $events[1]->occurred_at_utc);
        self::assertLessThan((string) $events[1]->event_id, (string) $events[0]->event_id);

        $defect->update(['status' => QualityDefectStatusEnum::IN_PROGRESS]);
        $history = $defect->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => QualityDefectStatusEnum::ASSIGNED,
            'to_status' => QualityDefectStatusEnum::IN_PROGRESS,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:29:59.123456',
        ]);
        $inverted = (new QualityDefectFlowOwnerEventFactory)->make(
            $defect,
            $history,
            QualityDefectFlowEventKind::STARTED,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quality_defect_flow_time_inversion');
        DB::transaction(fn (): string => $this->store()->append($inverted));
    }

    public function test_database_rejects_cross_project_lineage_invalid_transition_and_terminal_reason(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);
        $proved = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Policy seed'),
        );
        $policyId = (int) DB::table('quality_defect_flow_policies')->value('id');

        $unproven = QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'defect_number' => 'LINEAGE-'.bin2hex(random_bytes(4)),
            'title' => 'Lineage target',
            'severity' => 'major',
            'status' => QualityDefectStatusEnum::OPEN,
            'inspection_required' => true,
        ]);
        $history = $unproven->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => QualityDefectStatusEnum::OPEN,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:30:00.123456',
        ]);
        $created = (new QualityDefectFlowOwnerEventFactory)->make(
            $unproven,
            $history,
            QualityDefectFlowEventKind::CREATED,
        );
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $wrongSnapshot = $created->snapshot->canonical();
        $wrongSnapshot['project_id'] = (string) $otherProject->id;
        $crossProject = $this->rawEvent($created, $policyId, [
            'project_id' => $otherProject->id,
            'business_snapshot' => QualityDefectFlowCanonicalJson::encode($wrongSnapshot),
        ]);

        $this->assertSqlState($this->captureQueryException(static function () use ($crossProject): void {
            DB::table('quality_defect_flow_events')->insert($crossProject);
        }), '23514');

        $proved = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Terminal target'),
        );
        $terminalOccurredAt = now()->addMinute();
        $proved->update(['status' => QualityDefectStatusEnum::CANCELLED]);
        $cancelHistory = $proved->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => QualityDefectStatusEnum::OPEN,
            'to_status' => QualityDefectStatusEnum::CANCELLED,
            'changed_by' => $actor->id,
            'changed_at' => $terminalOccurredAt,
        ]);
        $cancelEvent = (new QualityDefectFlowOwnerEventFactory)->make(
            $proved,
            $cancelHistory,
            QualityDefectFlowEventKind::CANCELLED,
            QualityDefectFlowTerminalReason::CANCELLED_BY_USER,
        );
        $invalidReason = $this->rawEvent($cancelEvent, $policyId, [
            'sequence_no' => 2,
            'terminal_reason' => 'unsupported_reason',
        ]);
        $this->assertSqlState($this->captureQueryException(static function () use ($invalidReason): void {
            DB::table('quality_defect_flow_events')->insert($invalidReason);
        }), '23514');

        $invalidTransition = $this->rawEvent($cancelEvent, $policyId, [
            'event_id' => (string) Str::uuid7($cancelEvent->occurredAt),
            'sequence_no' => 2,
            'event_kind' => 'started',
            'terminal_reason' => null,
        ]);
        $this->assertSqlState($this->captureQueryException(static function () use ($invalidTransition): void {
            DB::table('quality_defect_flow_events')->insert($invalidTransition);
        }), '23514');
    }

    public function test_database_recomputes_policy_source_identity_source_and_evidence_hashes(): void
    {
        $policy = \App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowPolicyDefinition::v1();
        $this->assertSqlState($this->captureQueryException(static function () use ($policy): void {
            DB::table('quality_defect_flow_policies')->insert([
                'policy_code' => $policy->policyCode,
                'version' => $policy->version,
                'canonical_policy' => QualityDefectFlowCanonicalJson::encode($policy->canonicalPolicy()),
                'policy_hash' => str_repeat('f', 64),
                'created_at' => now(),
            ]);
        }), '23514');

        [$organization, $project, $actor] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);
        $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Policy seed'),
        );
        $policyId = (int) DB::table('quality_defect_flow_policies')->value('id');
        $unproven = QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'defect_number' => 'HASH-'.bin2hex(random_bytes(4)),
            'title' => 'Hash target',
            'severity' => 'major',
            'status' => QualityDefectStatusEnum::OPEN,
            'inspection_required' => true,
        ]);
        $history = $unproven->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => QualityDefectStatusEnum::OPEN,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:30:00.123456',
        ]);
        $event = (new QualityDefectFlowOwnerEventFactory)->make(
            $unproven,
            $history,
            QualityDefectFlowEventKind::CREATED,
        );

        foreach (['source_identity_hash', 'source_hash', 'evidence_hash', 'policy_hash'] as $field) {
            $raw = $this->rawEvent($event, $policyId, [$field => str_repeat('f', 64)]);
            $this->assertSqlState($this->captureQueryException(static function () use ($raw): void {
                DB::table('quality_defect_flow_events')->insert($raw);
            }), '23514');
        }

        $wrongPolicyVersion = $this->rawEvent($event, $policyId, ['policy_version' => 2]);
        $this->assertSqlState($this->captureQueryException(static function () use ($wrongPolicyVersion): void {
            DB::table('quality_defect_flow_events')->insert($wrongPolicyVersion);
        }), '23514');
    }

    public function test_database_rejects_organization_contractor_and_schedule_task_lineage(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);
        $proved = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Lineage policy seed'),
        );
        $policyId = (int) DB::table('quality_defect_flow_policies')->value('id');
        $otherOrganization = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $this->assertSqlState($this->captureQueryException(static function () use ($proved, $otherProject): void {
            DB::table('quality_defects')->where('id', $proved->id)->update([
                'project_id' => $otherProject->id,
            ]);
        }), '55000');
        $this->assertSqlState($this->captureQueryException(static function () use ($project, $otherOrganization): void {
            DB::table('projects')->where('id', $project->id)->update([
                'organization_id' => $otherOrganization->id,
            ]);
        }), '55000');
        $foreignContractorId = (int) DB::table('contractors')->insertGetId([
            'organization_id' => $otherOrganization->id,
            'name' => 'Foreign contractor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scheduleId = (int) DB::table('project_schedules')->insertGetId([
            'project_id' => $otherProject->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $actor->id,
            'name' => 'Foreign project schedule',
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignTaskId = (int) DB::table('schedule_tasks')->insertGetId([
            'schedule_id' => $scheduleId,
            'organization_id' => $organization->id,
            'created_by_user_id' => $actor->id,
            'name' => 'Foreign project task',
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-02',
            'planned_duration_days' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $makeEvent = function (?int $contractorId, ?int $taskId, string $suffix) use (
            $organization,
            $project,
            $actor,
        ): QualityDefectFlowEvent {
            $defect = QualityDefect::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'contractor_id' => $contractorId,
                'schedule_task_id' => $taskId,
                'created_by' => $actor->id,
                'defect_number' => 'LINEAGE-'.$suffix.'-'.bin2hex(random_bytes(3)),
                'title' => 'Lineage target',
                'severity' => 'major',
                'status' => QualityDefectStatusEnum::OPEN,
                'inspection_required' => true,
            ]);
            $history = $defect->statusHistory()->create([
                'organization_id' => $organization->id,
                'from_status' => null,
                'to_status' => QualityDefectStatusEnum::OPEN,
                'changed_by' => $actor->id,
                'changed_at' => '2026-08-01 09:30:00.123456',
            ]);

            return (new QualityDefectFlowOwnerEventFactory)->make(
                $defect,
                $history,
                QualityDefectFlowEventKind::CREATED,
            );
        };

        $organizationEvent = $makeEvent(null, null, 'ORG');
        $organizationSnapshot = $organizationEvent->snapshot->canonical();
        $organizationSnapshot['organization_id'] = (string) $otherOrganization->id;
        $wrongOrganization = $this->rawEvent($organizationEvent, $policyId, [
            'organization_id' => $otherOrganization->id,
            'business_snapshot' => QualityDefectFlowCanonicalJson::encode($organizationSnapshot),
        ]);
        $this->assertSqlState($this->captureQueryException(static function () use ($wrongOrganization): void {
            DB::table('quality_defect_flow_events')->insert($wrongOrganization);
        }), '23514');

        foreach ([
            'contractor' => $makeEvent($foreignContractorId, null, 'CONTRACTOR'),
            'schedule task' => $makeEvent(null, $foreignTaskId, 'TASK'),
        ] as $event) {
            $raw = $this->rawEvent($event, $policyId);
            $this->assertSqlState($this->captureQueryException(static function () use ($raw): void {
                DB::table('quality_defect_flow_events')->insert($raw);
            }), '23514');
        }
    }

    public function test_database_enforces_restricted_project_membership_for_actor_and_assignee(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $restricted = User::factory()->create(['current_organization_id' => $organization->id]);
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $restricted->id,
            'is_active' => true,
            'is_owner' => false,
            'project_access_mode' => 'restricted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->app->make(QualityDefectService::class);

        $this->assertSqlState($this->captureQueryException(
            fn (): QualityDefect => $service->create(
                (int) $organization->id,
                (int) $restricted->id,
                $this->createData($project, 'Restricted actor'),
            ),
        ), '23514');

        $defect = $service->create(
            (int) $organization->id,
            (int) $actor->id,
            $this->createData($project, 'Restricted assignee'),
        );
        $this->assertSqlState($this->captureQueryException(
            fn (): QualityDefect => $service->assign(
                $defect,
                (int) $restricted->id,
                (int) $actor->id,
            ),
        ), '23514');
        self::assertSame(QualityDefectStatusEnum::OPEN->value, QualityDefect::query()
            ->whereKey($defect->id)
            ->value('status'));
    }

    public function test_acceptance_lineage_cannot_be_retargeted_after_evidence_is_emitted(): void
    {
        [$organization, $project, $actor, $assignee] = $this->identityFixture();
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'title' => 'Acceptance source',
            'status' => 'in_progress',
        ]);
        $session = AcceptanceSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $actor->id,
            'status' => 'in_progress',
            'participant_user_ids' => [],
        ]);
        $finding = $this->app->make(HandoverAcceptanceService::class)->addFinding($session, (int) $actor->id, [
            'create_quality_defect' => true,
            'title' => 'Pinned acceptance source',
            'severity' => 'major',
            'quality_defect_inspection_required' => true,
        ]);

        $exception = $this->captureQueryException(static function () use ($session, $otherProject): void {
            DB::table('acceptance_sessions')->where('id', $session->id)->update([
                'project_id' => $otherProject->id,
            ]);
        });

        $this->assertSqlState($exception, '55000');
        self::assertSame((int) $project->id, (int) DB::table('acceptance_sessions')
            ->where('id', $session->id)
            ->value('project_id'));

        $replacementScope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'title' => 'Replacement source',
            'status' => 'in_progress',
        ]);
        $replacementSession = AcceptanceSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $replacementScope->id,
            'created_by_user_id' => $actor->id,
            'status' => 'in_progress',
            'participant_user_ids' => [],
        ]);
        $defect = QualityDefect::query()->findOrFail($finding->quality_defect_id);
        $defect->update(['metadata' => [
            'source' => [
                'type' => 'acceptance_finding',
                'acceptance_scope_id' => (int) $replacementScope->id,
                'acceptance_session_id' => (int) $replacementSession->id,
            ],
        ]]);

        $this->assertSqlState($this->captureQueryException(
            fn (): QualityDefect => $this->app->make(QualityDefectService::class)->assign(
                $defect,
                (int) $assignee->id,
                (int) $actor->id,
            ),
        ), '23514');
        self::assertSame(QualityDefectStatusEnum::OPEN->value, QualityDefect::query()
            ->whereKey($defect->id)
            ->value('status'));
    }

    public function test_append_only_source_supports_bounded_keyset_order_at_project_scale(): void
    {
        [$organization, $project, $actor] = $this->identityFixture();
        $service = $this->app->make(QualityDefectService::class);

        for ($index = 1; $index <= 50; $index++) {
            $service->create(
                (int) $organization->id,
                (int) $actor->id,
                $this->createData($project, "Scale defect {$index}"),
            );
        }

        self::assertSame(50, DB::table('quality_defect_flow_events')
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->count());
        $page = DB::table('quality_defect_flow_events')
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->orderBy('occurred_at_utc')
            ->orderBy('event_id')
            ->limit(20)
            ->get(['occurred_at_utc', 'event_id']);

        self::assertCount(20, $page);
        self::assertSame(
            $page->pluck('event_id')->all(),
            $page->sortBy([
                ['occurred_at_utc', 'asc'],
                ['event_id', 'asc'],
            ])->pluck('event_id')->values()->all(),
        );
    }

    public function test_concurrent_exact_replay_and_conflicting_duplicate_are_serialized(): void
    {
        $this->runProcessRace(false);
        $this->runProcessRace(true);
    }

    private function identityFixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $assignee = User::factory()->create(['current_organization_id' => $organization->id]);
        foreach ([$actor, $assignee] as $user) {
            DB::table('organization_user')->insert([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'is_active' => true,
                'is_owner' => false,
                'project_access_mode' => 'all',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$organization, $project, $actor, $assignee];
    }

    private function createData(Project $project, string $title = 'Quality defect'): array
    {
        return [
            'project_id' => (int) $project->id,
            'title' => $title,
            'severity' => 'major',
            'inspection_required' => true,
        ];
    }

    private function legacyDefect(
        Organization $organization,
        Project $project,
        User $actor,
        QualityDefectStatusEnum $status,
    ): QualityDefect {
        $defect = QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'defect_number' => 'LEGACY-'.bin2hex(random_bytes(4)),
            'title' => 'Legacy defect',
            'severity' => 'major',
            'status' => $status,
            'inspection_required' => true,
        ]);
        $defect->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => QualityDefectStatusEnum::OPEN,
            'to_status' => $status,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:30:00.123456',
        ]);

        return $defect;
    }

    private function eventForLatestHistory(
        QualityDefect $defect,
        QualityDefectFlowEventKind $eventKind,
    ): QualityDefectFlowEvent {
        $history = QualityDefectStatusHistory::query()
            ->where('quality_defect_id', $defect->id)
            ->orderByDesc('id')
            ->firstOrFail();

        return (new QualityDefectFlowOwnerEventFactory)->make($defect->fresh(), $history, $eventKind);
    }

    private function store(): EloquentQualityDefectFlowStore
    {
        return new EloquentQualityDefectFlowStore(new QualityDefectFlowIdempotencyGuard);
    }

    private function rawEvent(QualityDefectFlowEvent $event, int $policyId, array $overrides = []): array
    {
        $snapshot = $event->snapshot->canonical();
        $sourceLink = $snapshot['source_link'];
        $eventId = (string) Str::uuid7($event->occurredAt);
        $sequenceNo = 1;

        return array_replace([
            'event_id' => $eventId,
            'organization_id' => (int) $snapshot['organization_id'],
            'project_id' => (int) $snapshot['project_id'],
            'quality_defect_id' => (int) $snapshot['quality_defect_id'],
            'contractor_id' => $snapshot['contractor_id'],
            'schedule_task_id' => $snapshot['schedule_task_id'],
            'acceptance_scope_id' => $sourceLink['acceptance_scope_id'] ?? null,
            'acceptance_session_id' => $sourceLink['acceptance_session_id'] ?? null,
            'actor_id' => $event->actorId,
            'assignee_id' => $snapshot['assignee_id'],
            'occurred_at_utc' => $event->occurredAtUtc(),
            'sequence_no' => $sequenceNo,
            'event_kind' => $event->eventKind->value,
            'from_status' => $event->fromStatus?->value,
            'to_status' => $event->toStatus->value,
            'terminal_reason' => $event->terminalReason?->value,
            'policy_id' => $policyId,
            'policy_version' => $event->policy->version,
            'policy_hash' => $event->policyHash(),
            'business_snapshot' => QualityDefectFlowCanonicalJson::encode($snapshot),
            'source_identity' => QualityDefectFlowCanonicalJson::encode($event->sourceIdentity),
            'source_identity_hash' => $event->sourceIdentityHash(),
            'source_hash' => $event->sourceHash(),
            'evidence_hash' => $event->evidenceHash($eventId, $sequenceNo),
            'created_at' => $event->occurredAtUtc(),
        ], $overrides);
    }

    private function runProcessRace(bool $conflictingPayload): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')) {
            if (getenv('CI') === 'true') {
                self::fail('CI PostgreSQL quality-defect-flow race gate requires pcntl and posix extensions.');
            }

            $this->markTestSkipped('Requires pcntl and posix extensions for a real PostgreSQL process race.');
        }

        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'quality-defect-flow-race-'.bin2hex(random_bytes(8)),
        );
        $suffix = bin2hex(random_bytes(5));
        $connections = [
            "quality_flow_fixture_{$suffix}",
            "quality_flow_winner_{$suffix}",
            "quality_flow_contender_{$suffix}",
            "quality_flow_observer_{$suffix}",
        ];
        $originalDefault = (string) config('database.default');
        $children = [];
        $fixture = null;
        $fixtureConnection = null;
        $winnerConnection = null;
        $observerConnection = null;

        try {
            $fixtureConnection = $harness->independentConnection($connections[0]);
            $winnerConnection = $harness->independentConnection($connections[1]);
            $harness->independentConnection($connections[2]);
            $observerConnection = $harness->independentConnection($connections[3]);
            if (! $fixtureConnection instanceof Connection
                || ! $winnerConnection instanceof Connection
                || ! $observerConnection instanceof Connection) {
                throw new RuntimeException('Quality-defect-flow race connections must be Laravel connections.');
            }

            DB::setDefaultConnection($connections[0]);
            $fixture = $this->committedRaceFixture($suffix);
            $winnerEvent = $fixture['event'];
            $contenderEvent = $conflictingPayload
                ? new QualityDefectFlowEvent(
                    eventKind: $winnerEvent->eventKind,
                    fromStatus: $winnerEvent->fromStatus,
                    toStatus: $winnerEvent->toStatus,
                    actorId: $winnerEvent->actorId,
                    occurredAt: $winnerEvent->occurredAt->modify('+1 second'),
                    snapshot: $winnerEvent->snapshot,
                    sourceIdentity: $winnerEvent->sourceIdentity,
                    policy: $winnerEvent->policy,
                )
                : $winnerEvent;

            $winnerConnection->beginTransaction();
            DB::setDefaultConnection($connections[1]);
            $this->store()->append($winnerEvent);
            DB::setDefaultConnection($connections[2]);
            $children[] = $harness->spawn(1, static function () use (
                $contenderEvent,
                $conflictingPayload,
            ): array {
                try {
                    $eventId = DB::transaction(static fn (): string => (new EloquentQualityDefectFlowStore(
                        new QualityDefectFlowIdempotencyGuard,
                    ))->append($contenderEvent));

                    return ['outcome' => 'replay', 'event_id' => $eventId];
                } catch (LogicException $exception) {
                    if (! $conflictingPayload) {
                        throw $exception;
                    }

                    return ['outcome' => 'conflict', 'message' => $exception->getMessage()];
                }
            });
            DB::setDefaultConnection($originalDefault);

            $harness->release(1);
            $backendPid = $harness->waitForWorkerBackendPid(1);
            $harness->waitForPostgresWait($observerConnection, $backendPid);
            $winnerConnection->commit();
            $harness->waitForChildren($children);
            $result = $harness->result(1);

            self::assertSame($conflictingPayload ? 'conflict' : 'replay', $result['outcome']);
            if ($conflictingPayload) {
                self::assertSame('quality_defect_flow_idempotency_conflict', $result['message'] ?? null);
            }
            self::assertSame(1, $observerConnection->table('quality_defect_flow_events')
                ->where('quality_defect_id', $fixture['quality_defect_id'])
                ->count());
        } finally {
            DB::setDefaultConnection($originalDefault);
            $failure = null;
            if ($winnerConnection instanceof Connection && $winnerConnection->transactionLevel() > 0) {
                $harness->cleanupStep(static fn () => $winnerConnection->rollBack(), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->terminateAndReap($children), $failure);
            if ($fixture !== null && $fixtureConnection instanceof Connection) {
                $harness->cleanupStep(
                    fn () => $this->cleanupCommittedRaceFixture($fixtureConnection, $fixture),
                    $failure,
                );
            }
            foreach ($connections as $connection) {
                $harness->cleanupStep(static fn () => DB::purge($connection), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->cleanup(), $failure);

            if ($failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    private function committedRaceFixture(string $suffix): array
    {
        $organization = Organization::factory()->create(['name' => "Quality flow race {$suffix}"]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create([
            'email' => "quality-flow-race-{$suffix}@example.test",
            'current_organization_id' => $organization->id,
        ]);
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'is_active' => true,
            'is_owner' => false,
            'project_access_mode' => 'all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $defect = QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'defect_number' => "RACE-{$suffix}",
            'title' => 'Race target',
            'severity' => 'major',
            'status' => QualityDefectStatusEnum::OPEN,
            'inspection_required' => true,
        ]);
        $history = $defect->statusHistory()->create([
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => QualityDefectStatusEnum::OPEN,
            'changed_by' => $actor->id,
            'changed_at' => '2026-08-01 09:30:00.123456',
        ]);

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'user_id' => (int) $actor->id,
            'quality_defect_id' => (int) $defect->id,
            'event' => (new QualityDefectFlowOwnerEventFactory)->make(
                $defect,
                $history,
                QualityDefectFlowEventKind::CREATED,
            ),
        ];
    }

    private function cleanupCommittedRaceFixture(Connection $connection, array $fixture): void
    {
        $connection->transaction(function () use ($connection, $fixture): void {
            foreach ([
                'quality_defect_flow_events',
                'quality_defect_flow_gaps',
                'quality_defect_flow_policies',
            ] as $table) {
                $connection->unprepared("ALTER TABLE {$table} DISABLE TRIGGER {$table}_reject_mutation");
            }

            try {
                $connection->table('quality_defect_flow_events')
                    ->where('organization_id', $fixture['organization_id'])
                    ->delete();
                $connection->table('quality_defect_flow_gaps')
                    ->where('organization_id', $fixture['organization_id'])
                    ->delete();
                if ($connection->table('quality_defect_flow_events')->count() === 0
                    && $connection->table('quality_defect_flow_gaps')->count() === 0) {
                    $connection->table('quality_defect_flow_policies')
                        ->where('policy_code', 'quality-defect-flow.v1')
                        ->where('version', 1)
                        ->delete();
                }
            } finally {
                foreach ([
                    'quality_defect_flow_policies',
                    'quality_defect_flow_gaps',
                    'quality_defect_flow_events',
                ] as $table) {
                    $connection->unprepared("ALTER TABLE {$table} ENABLE TRIGGER {$table}_reject_mutation");
                }
            }

            $connection->table('quality_defect_status_history')
                ->where('quality_defect_id', $fixture['quality_defect_id'])
                ->delete();
            $connection->table('quality_defects')->where('id', $fixture['quality_defect_id'])->delete();
            $connection->table('organization_user')
                ->where('organization_id', $fixture['organization_id'])
                ->where('user_id', $fixture['user_id'])
                ->delete();
            $connection->table('projects')->where('id', $fixture['project_id'])->delete();
            $connection->table('organizations')->where('id', $fixture['organization_id'])->delete();
            $connection->table('users')->where('id', $fixture['user_id'])->delete();
        });
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

    private function assertSqlState(QueryException $exception, string $expected): void
    {
        self::assertSame($expected, $exception->errorInfo[0] ?? null, $exception->getMessage());
    }
}

final class FailingQualityDefectFlowOwnerSink implements QualityDefectFlowOwnerEventSink
{
    public function record(
        QualityDefect $defect,
        QualityDefectStatusHistory $history,
        QualityDefectFlowEventKind $eventKind,
        ?\App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason $terminalReason = null,
    ): string {
        throw new RuntimeException('quality_defect_flow_recorder_failure');
    }
}
