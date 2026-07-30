<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\ProductionReportScopedResourceAuthorizers;
use App\BusinessModules\Core\Reporting\Support\CompletedReportSourceLedgerBinding;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Backfill\QualityDefectFlowBackfill;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill\WorkforceAdmissionBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyExposureBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyIncidentBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyTransitionRecorder;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyManagementService;
use App\Jobs\ReportingSourceBackfillJob;
use App\Jobs\SafetyExposureZeroFillJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

#[Group('postgres')]
final class SafetyIncidentReportingPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function site_transition_exposure_and_snapshot_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'safety_site_code_unique',
                'safety_transition_event_version_unique',
                'safety_exposure_day_unique',
                'safety_incident_snapshot_frequency_check',
                'safety_incident_policy_terminal_statuses_check',
                'safety_incident_policy_no_overlap',
                'safety_incident_policy_rules_check',
                'safety_transition_event_evidence_id_check',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'safety_exposure_day_unique',
            'safety_incident_policy_no_overlap',
            'safety_incident_policy_rules_check',
            'safety_incident_policy_terminal_statuses_check',
            'safety_incident_snapshot_frequency_check',
            'safety_site_code_unique',
            'safety_transition_event_evidence_id_check',
            'safety_transition_event_version_unique',
        ], $constraints);

        $triggers = collect(DB::select(
            "select tgname from pg_trigger where not tgisinternal and tgname in ('safety_transition_events_immutable', 'safety_incident_policies_immutable')",
        ))->pluck('tgname')->sort()->values()->all();
        self::assertSame([
            'safety_incident_policies_immutable',
            'safety_transition_events_immutable',
        ], $triggers);
    }

    #[Test]
    public function domain_transition_and_reporting_event_are_persisted_by_one_service_workflow(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $incident = SafetyIncident::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $actor->id,
            'incident_number' => 'HSE-I-ATOMIC-1',
            'title' => 'Проверка атомарного перехода',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => now()->subMinute(),
        ]);

        $result = app(SafetyManagementService::class)->triageIncident($incident, (int) $actor->id, null);

        self::assertSame('triage', $result->status);
        self::assertDatabaseHas('safety_transition_events', [
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'subject_type' => 'incident',
            'subject_id' => $incident->id,
            'from_status' => 'reported',
            'to_status' => 'triage',
            'actor_user_id' => $actor->id,
        ]);
        self::assertSame(1, SafetyTransitionEvent::query()
            ->where('subject_type', 'incident')
            ->where('subject_id', $incident->id)
            ->where('to_status', 'triage')
            ->count());
    }

    #[Test]
    public function reporting_event_failure_rolls_back_the_domain_transition(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $incident = SafetyIncident::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $actor->id,
            'incident_number' => 'HSE-I-ATOMIC-ROLLBACK-1',
            'title' => 'Проверка отката перехода',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => now()->subMinute(),
        ]);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_safety_reporting_test_event() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'reporting_event_rejected';
END;
$$;
CREATE TRIGGER reject_safety_reporting_test_event
BEFORE INSERT ON safety_transition_events
FOR EACH ROW EXECUTE FUNCTION reject_safety_reporting_test_event();
SQL);

        $failure = null;
        try {
            app(SafetyManagementService::class)->triageIncident($incident, (int) $actor->id, null);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS reject_safety_reporting_test_event ON safety_transition_events;
DROP FUNCTION IF EXISTS reject_safety_reporting_test_event();
SQL);
        }

        self::assertInstanceOf(Throwable::class, $failure);
        $incident->refresh();
        self::assertSame('reported', $incident->status);
        self::assertNull($incident->triaged_at);
        self::assertDatabaseMissing('safety_transition_events', [
            'subject_type' => 'incident',
            'subject_id' => $incident->id,
            'to_status' => 'triage',
        ]);
    }

    #[Test]
    public function any_owner_content_mutation_invalidates_the_completed_ledger_checksum(): void
    {
        $this->requirePostgres();
        Queue::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $incident = SafetyIncident::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $actor->id,
            'incident_number' => 'HSE-CONTENT-HASH-1',
            'title' => 'Initial owner content',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => now()->subMinute(),
        ]);

        ReportingSourceBackfillJob::request(
            (int) $organization->id,
            ReportingSourceBackfillJob::SAFETY_INCIDENTS,
        );
        $ledger = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $organization->id)
            ->where('source_code', ReportingSourceBackfillJob::SAFETY_INCIDENTS)
            ->first();
        self::assertNotNull($ledger);
        $firstChecksum = (string) $ledger->owner_checksum;
        DB::table('report_source_sync_ledgers')->where('id', $ledger->id)->update([
            'completed_owner_checksum' => $firstChecksum,
            'status' => 'ready',
        ]);

        $incident->update(['title' => 'Mutated owner content']);
        ReportingSourceBackfillJob::request(
            (int) $organization->id,
            ReportingSourceBackfillJob::SAFETY_INCIDENTS,
        );
        $ledger = DB::table('report_source_sync_ledgers')->where('id', $ledger->id)->first();

        self::assertNotSame($firstChecksum, (string) $ledger->owner_checksum);
        self::assertNull($ledger->completed_owner_checksum);
        self::assertSame('pending', $ledger->status);
    }

    #[Test]
    public function violation_resolution_authorizer_uses_the_exact_evidence_id_and_current_owner_state(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $violation = SafetyViolation::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'resolved_by_user_id' => $actor->id,
            'violation_number' => 'HSE-RESOLUTION-AUTH-1',
            'title' => 'Resolution evidence',
            'severity' => 'major',
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_comment' => 'Verified resolution',
        ]);
        $resource = new ReportScopedResource(
            'violation_resolution',
            (int) $violation->id,
            (int) $project->id,
        );
        $facts = new CurrentReportAuthorizationFacts(
            'queue',
            (int) $actor->id,
            (int) $organization->id,
            (int) $project->id,
            $resource,
            new DateTimeImmutable,
        );
        $authorizer = collect((new ProductionReportScopedResourceAuthorizers)->handlers())
            ->first(static fn ($handler): bool => $handler->kind() === 'violation_resolution');
        self::assertNotNull($authorizer);
        self::assertTrue($authorizer->authorize($actor, (int) $organization->id, $resource, $facts)->granted);

        $violation->update(['status' => 'open']);

        self::assertFalse($authorizer->authorize($actor, (int) $organization->id, $resource, $facts)->granted);
    }

    #[Test]
    public function safety_replay_recomputes_the_exact_event_hash_and_rejects_changed_evidence(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $violation = SafetyViolation::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'resolved_by_user_id' => $actor->id,
            'violation_number' => 'HSE-REPLAY-HASH-1',
            'title' => 'Replay evidence',
            'severity' => 'major',
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_comment' => 'Original resolution',
        ]);
        $recorder = app(SafetyTransitionRecorder::class);
        $recorder->record(
            $violation,
            'open',
            'resolved',
            (int) $actor->id,
            $violation->resolved_at,
        );
        $violation->resolution_comment = 'Changed resolution';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('safety_transition_conflict');

        $recorder->record(
            $violation,
            'open',
            'resolved',
            (int) $actor->id,
            $violation->resolved_at,
        );
    }

    #[Test]
    public function completed_snapshot_binding_never_matches_a_later_rebuild_generation(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $firstChecksum = str_repeat('a', 64);
        $secondChecksum = str_repeat('b', 64);
        DB::table('report_source_sync_ledgers')->insert([
            'organization_id' => $organization->id,
            'source_code' => ReportingSourceBackfillJob::SAFETY_INCIDENTS,
            'cursor' => '{}',
            'target_cursor' => '{}',
            'owner_checksum' => $firstChecksum,
            'completed_owner_checksum' => $firstChecksum,
            'status' => 'ready',
            'source_count' => 0,
            'projected_count' => 0,
            'gap_count' => 0,
            'unknown_count' => 0,
            'unknown_owner_keys' => '[]',
            'source_watermark' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $binding = CompletedReportSourceLedgerBinding::capture(
            (int) $organization->id,
            [ReportingSourceBackfillJob::SAFETY_INCIDENTS],
        );
        DB::table('report_source_sync_ledgers')
            ->where('organization_id', $organization->id)
            ->where('source_code', ReportingSourceBackfillJob::SAFETY_INCIDENTS)
            ->update([
                'owner_checksum' => $secondChecksum,
                'completed_owner_checksum' => $secondChecksum,
                'completed_at' => now()->addSecond(),
                'updated_at' => now()->addSecond(),
            ]);

        self::assertFalse(CompletedReportSourceLedgerBinding::matches(
            (int) $organization->id,
            $binding,
        ));
    }

    #[Test]
    public function missing_attendance_evidence_is_not_treated_as_authoritative_zero(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $site = SafetySite::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'code' => 'ZERO-UNKNOWN',
            'name' => 'Unknown attendance',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'active_from' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $job = new SafetyExposureZeroFillJob(
            (int) $organization->id,
            '2026-07-30',
            '2026-07-30',
        );
        $method = new \ReflectionMethod(SafetyExposureZeroFillJob::class, 'hasAuthoritativeZeroAttendance');

        self::assertFalse($method->invoke(
            $job,
            (int) $project->id,
            (int) $site->id,
            CarbonImmutable::parse('2026-07-30'),
        ));
    }

    #[Test]
    public function owner_mutation_before_job_completion_retargets_instead_of_completing_a_stale_generation(): void
    {
        $this->requirePostgres();
        Queue::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $incident = SafetyIncident::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $actor->id,
            'incident_number' => 'HSE-MUTATION-DURING-JOB-1',
            'title' => 'Pinned generation',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => now()->subMinute(),
        ]);
        ReportingSourceBackfillJob::request(
            (int) $organization->id,
            ReportingSourceBackfillJob::SAFETY_INCIDENTS,
        );
        $before = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $organization->id)
            ->where('source_code', ReportingSourceBackfillJob::SAFETY_INCIDENTS)
            ->first();
        self::assertNotNull($before);
        $incident->update(['title' => 'Mutated generation']);

        (new ReportingSourceBackfillJob(
            (int) $organization->id,
            ReportingSourceBackfillJob::SAFETY_INCIDENTS,
        ))->handle(
            app(QualityDefectFlowBackfill::class),
            app(SafetyIncidentBackfill::class),
            app(SafetyExposureBackfill::class),
            app(WorkforceAdmissionBackfill::class),
        );
        $after = DB::table('report_source_sync_ledgers')->where('id', $before->id)->first();
        self::assertNotNull($after);

        self::assertNotSame((string) $before->owner_checksum, (string) $after->owner_checksum);
        self::assertSame('pending', $after->status);
        self::assertSame([], json_decode((string) $after->cursor, true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($after->completed_owner_checksum);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
