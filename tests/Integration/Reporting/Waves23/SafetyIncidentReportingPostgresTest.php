<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\ProductionReportScopedResourceAuthorizers;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyManagementService;
use App\Jobs\ReportingSourceBackfillJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
