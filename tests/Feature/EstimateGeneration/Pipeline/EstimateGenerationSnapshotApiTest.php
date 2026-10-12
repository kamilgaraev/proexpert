<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

#[Group('database')]
#[Group('postgres')]
final class EstimateGenerationSnapshotApiTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[Test]
    public function unchanged_snapshot_returns_empty_not_modified_response(): void
    {
        [$user, $project, $session] = $this->fixture();
        $this->route($project, $session);
        $this->withToken(auth('api_admin')->login($user));
        $first = $this->getJson('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id);
        $etag = (string) $first->headers->get('ETag');
        $second = $this->withHeader('If-None-Match', '"other", W/'.$etag)
            ->get('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id);

        $first->assertOk()->assertJsonStructure(['data' => [
            'id', 'project_id', 'status', 'state_version', 'operational_version',
            'processing_stage', 'processing_progress', 'current_checkpoint',
            'queue_summary', 'recovery_summary', 'available_actions', 'blocking_issues',
            'warnings', 'next_action', 'documents_summary', 'estimate_summary',
            'review_summary', 'evidence_summary', 'quality_summary', 'usage_summary', 'failure_summary',
        ]]);
        $second->assertStatus(304);
        self::assertSame('', $second->getContent());
        self::assertSame($etag, $second->headers->get('ETag'));
        self::assertEqualsCanonicalizing(['private', 'no-cache', 'must-revalidate'],
            array_map('trim', explode(',', (string) $second->headers->get('Cache-Control'))));
    }

    #[Test]
    public function authorization_precedes_conditional_response(): void
    {
        [, $project, $session] = $this->fixture();
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        $this->route($project, $session);
        $this->withToken(auth('api_admin')->login($foreign));

        $this->withHeader('If-None-Match', '*')
            ->get('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id)
            ->assertForbidden();
    }

    #[Test]
    public function snapshot_exposes_current_month_ai_estimate_quota_and_reservation_status(): void
    {
        [$user, $project, $session] = $this->fixture();
        app(AiEstimateQuotaService::class)->reserve($session);
        $this->route($project, $session);
        $this->withToken(auth('api_admin')->login($user));

        $this->getJson('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('data.ai_estimate_quota', [
                'included' => 10,
                'purchased' => 0,
                'used' => 0,
                'reserved' => 1,
                'available' => 9,
                'reservation_status' => 'reserved',
            ]);

        DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $project->organization_id)
            ->where('session_id', $session->id)
            ->update(['status' => 'released', 'released_at' => now()]);

        $this->getJson('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('data.ai_estimate_quota', [
                'included' => 10,
                'purchased' => 0,
                'used' => 0,
                'reserved' => 0,
                'available' => 10,
                'reservation_status' => 'released',
            ]);
    }

    #[Test]
    public function reserved_operation_in_another_session_invalidates_the_snapshot_etag_without_becoming_usage(): void
    {
        [$user, $project, $session] = $this->fixture();
        $otherSession = $this->anotherSession($user, $project);
        $this->route($project, $session);
        $this->withToken(auth('api_admin')->login($user));

        $first = $this->getJson('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id);
        $etag = (string) $first->headers->get('ETag');

        app(AiEstimateQuotaService::class)->reserve($otherSession);

        $second = $this->withHeader('If-None-Match', $etag)
            ->get('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id);

        $second->assertOk()
            ->assertJsonPath('data.ai_estimate_quota.used', 0)
            ->assertJsonPath('data.ai_estimate_quota.reserved', 1);
        self::assertNotSame($etag, $second->headers->get('ETag'));
    }

    #[Test]
    public function quota_snapshot_batches_limits_usage_and_reservation_status_in_three_queries(): void
    {
        [$user, $project, $session] = $this->fixture();
        $otherSession = $this->anotherSession($user, $project);
        $selects = 0;
        DB::listen(static function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                $selects++;
            }
        });

        $snapshots = app(AiEstimateQuotaService::class)->snapshots([$session, $otherSession]);

        self::assertSame(AiEstimateQuotaService::SNAPSHOT_QUERY_BUDGET, $selects);
        self::assertSame(2, count($snapshots));
        self::assertSame(0, $snapshots[(int) $session->id]['used']);
    }

    #[Test]
    public function quota_snapshot_never_returns_negative_available_when_confirmed_usage_exceeds_limit(): void
    {
        [$user, $project, $session] = $this->fixture();
        $sessions = [$session];
        for ($index = 1; $index < 11; $index++) {
            $sessions[] = $this->anotherSession($user, $project);
        }
        $now = now();
        DB::table('estimate_generation_ai_estimate_quota_reservations')->insert(array_map(
            static fn (EstimateGenerationSession $reservedSession): array => [
                'organization_id' => $project->organization_id,
                'session_id' => $reservedSession->id,
                'monthly_period' => $now->copy()->startOfMonth()->toDateString(),
                'status' => 'confirmed',
                'reserved_at' => $now,
                'confirmed_at' => $now,
                'released_at' => null,
            ],
            $sessions,
        ));
        $this->route($project, $session);
        $this->withToken(auth('api_admin')->login($user));

        $this->getJson('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('data.ai_estimate_quota', [
                'included' => 10,
                'purchased' => 0,
                'used' => 11,
                'reserved' => 0,
                'available' => 0,
                'reservation_status' => 'confirmed',
            ]);
    }

    #[Test]
    public function operational_builder_stays_inside_its_authored_query_budget(): void
    {
        [, , $session] = $this->fixture();
        $selects = 0;
        DB::listen(static function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                $selects++;
            }
        });

        app(BuildSessionOperationalSnapshot::class)->handle($session, []);

        self::assertSame(BuildSessionOperationalSnapshot::QUERY_BUDGET, $selects);
    }

    #[Test]
    public function in_place_session_mutation_changes_operational_version(): void
    {
        [, , $session] = $this->fixture();
        $builder = app(BuildSessionOperationalSnapshot::class);
        $before = $builder->handle($session, [])->operationalVersion;

        EstimateGenerationSession::query()->whereKey($session->id)->update(['processing_progress' => 1]);
        $after = $builder->handle($session, [])->operationalVersion;

        self::assertNotSame($before, $after);
    }

    #[Test]
    public function textual_document_source_version_is_aggregated_without_numeric_cast_and_changes_revision(): void
    {
        [$user, $project, $session] = $this->fixture();
        $documentId = DB::table('estimate_generation_documents')->insertGetId([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'user_id' => $user->id,
            'filename' => 'plan.pdf',
            'status' => 'ready',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $builder = app(BuildSessionOperationalSnapshot::class);
        $before = $builder->handle($session, [])->operationalVersion;

        DB::table('estimate_generation_documents')->where('id', $documentId)->update([
            'source_version' => 'sha256:'.str_repeat('b', 64),
        ]);

        self::assertNotSame($before, $builder->handle($session, [])->operationalVersion);
    }

    #[Test]
    public function every_document_and_estimate_source_changes_operational_version(): void
    {
        [$user, $project, $session] = $this->fixture();
        $scope = ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'session_id' => $session->id];
        $documentId = DB::table('estimate_generation_documents')->insertGetId([...$scope, 'user_id' => $user->id, 'filename' => 'plan.pdf', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSourceMutation($session, 'document update', static fn () => DB::table('estimate_generation_documents')->where('id', $documentId)->update(['updated_at' => now()->addSecond()]));

        $pageId = 0;
        $this->assertSourceMutation($session, 'page insert', static function () use (&$pageId, $scope, $documentId): void {
            $pageId = DB::table('estimate_generation_document_pages')->insertGetId([...$scope, 'document_id' => $documentId, 'page_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertSourceMutation($session, 'fact insert', static fn () => DB::table('estimate_generation_document_facts')->insert([...$scope, 'document_id' => $documentId, 'page_id' => $pageId, 'fact_type' => 'total_area', 'label' => 'area', 'confidence' => 0.9, 'source_ref' => '{}', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'drawing insert', static fn () => DB::table('estimate_generation_drawing_elements')->insert([...$scope, 'document_id' => $documentId, 'page_id' => $pageId, 'type' => 'wall', 'confidence' => 0.9, 'source_ref' => '{}', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'quantity insert', static fn () => DB::table('estimate_generation_quantity_takeoffs')->insert([...$scope, 'document_id' => $documentId, 'page_id' => $pageId, 'work_intent' => '{}', 'name' => 'wall', 'unit' => 'm2', 'quantity' => 1, 'confidence' => 0.9, 'source_refs' => '[]', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'scope insert', static fn () => DB::table('estimate_generation_scope_inferences')->insert([...$scope, 'document_id' => $documentId, 'page_id' => $pageId, 'inference_type' => 'building', 'title' => 'building', 'source_refs' => '[]', 'work_intent' => '{}', 'confidence' => 0.9, 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'feedback insert', static fn () => DB::table('estimate_generation_feedback')->insert(['session_id' => $session->id, 'user_id' => $user->id, 'feedback_type' => 'review', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'audit insert', static fn () => DB::table('estimate_generation_audit_events')->insert(['session_id' => $session->id, 'user_id' => $user->id, 'event_type' => 'review', 'created_at' => now(), 'updated_at' => now()]));

        $packageId = 0;
        $this->assertSourceMutation($session, 'package insert', static function () use (&$packageId, $session): void {
            $packageId = DB::table('estimate_generation_packages')->insertGetId(['session_id' => $session->id, 'key' => 'main', 'title' => 'main', 'scope_type' => 'building', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertSourceMutation($session, 'item insert', static fn () => DB::table('estimate_generation_package_items')->insert(['package_id' => $packageId, 'key' => 'wall', 'item_type' => 'priced_work', 'name' => 'wall', 'total_cost' => 100, 'created_at' => now(), 'updated_at' => now()]));
        $attemptId = (string) Str::uuid();
        $this->assertSourceMutation($session, 'checkpoint insert', static fn () => DB::table('estimate_generation_pipeline_checkpoints')->insert([...$scope, 'generation_attempt_id' => $attemptId, 'base_input_version' => 'sha256:'.str_repeat('a', 64), 'stage' => 'understand_documents', 'input_version' => 'sha256:'.str_repeat('b', 64), 'status' => 'running', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addHour(), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        $unitSource = 'sha256:'.str_repeat('c', 64);
        $this->assertSourceMutation($session, 'processing unit insert', static fn () => DB::table('estimate_generation_processing_units')->insert([
            ...$scope, 'document_id' => $documentId, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $unitSource,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            'locator' => json_encode(['source_kind' => 'pdf', 'source_version' => $unitSource, 'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'fixture/private/page.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'fixture-page'),
                'artifact_version_id' => 'fixture.v1'], JSON_THROW_ON_ERROR),
        ]));
    }

    #[Test]
    public function manual_universal_input_reaches_the_operational_contract_without_extra_queries(): void
    {
        config()->set('estimate-generation.universal_enabled', true);
        [, , $session] = $this->fixture();
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'description' => 'Ремонт комнаты без размеров']]);
        $selects = 0;
        DB::listen(static function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                $selects++;
            }
        });

        $snapshot = app(BuildSessionOperationalSnapshot::class)->handle($session, ['estimate_generation.generate']);

        self::assertSame(BuildSessionOperationalSnapshot::QUERY_BUDGET, $selects);
        self::assertTrue($snapshot->documentsSummary['manual_input_available']);
        self::assertSame(0, $snapshot->documentsSummary['total']);
        self::assertTrue($snapshot->canGenerate);
        self::assertFalse($snapshot->canApply);
        self::assertContains('generate', array_column($snapshot->availableActions, 'action'));
        self::assertSame('draft', $snapshot->recommendedStep);
        self::assertTrue($snapshot->workflowSteps[3]['available']);
    }

    #[Test]
    public function universal_readiness_accepts_ready_photo_and_structured_input_but_honours_rollout_and_document_blocks(): void
    {
        config()->set('estimate-generation.universal_enabled', true);
        [, , $session] = $this->fixture();
        $session->input_payload = ['evaluation_mode' => 'universal'];
        $readiness = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Quality\UniversalEvaluationReadinessService::class);
        self::assertTrue($readiness->evaluate($session, ['ready' => 1])->canGenerate);
        self::assertFalse($readiness->evaluate($session, ['ready' => 1, 'pending' => 1])->canGenerate);
        self::assertFalse($readiness->evaluate($session, ['ready' => 1, 'system_failure_count' => 1])->canGenerate);
        self::assertFalse($readiness->evaluate($session, ['ready' => 1, 'action_required' => 1])->canGenerate);
        self::assertFalse($readiness->evaluate($session)->canGenerate);
        $session->input_payload = ['evaluation_mode' => 'universal', 'manual_input_revision' => 1];
        self::assertTrue($readiness->evaluate($session)->canGenerate);
        $session->status = EstimateGenerationStatus::Archived;
        self::assertFalse($readiness->evaluate($session)->canGenerate);
        $session->status = EstimateGenerationStatus::Cancelled;
        self::assertTrue($readiness->evaluate($session)->canGenerate);
        $session->status = EstimateGenerationStatus::Applied;
        self::assertTrue($readiness->evaluate($session)->canGenerate);
        config()->set('estimate-generation.universal_enabled', false);
        self::assertFalse($readiness->evaluate($session, ['ready' => 1])->canGenerate);
    }

    private function route(Project $project, EstimateGenerationSession $session): void
    {
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_snapshot/projects/{project}/sessions/{session}', [EstimateGenerationSessionController::class, 'snapshot'])
            ->middleware(['auth:api_admin', SubstituteBindings::class]);
    }

    private function assertSourceMutation(EstimateGenerationSession $session, string $source, callable $mutation): void
    {
        $builder = app(BuildSessionOperationalSnapshot::class);
        $before = $builder->handle($session, [])->operationalVersion;
        $mutation();
        $after = $builder->handle($session, [])->operationalVersion;

        self::assertNotSame($before, $after, $source);
    }

    private function anotherSession(User $user, Project $project): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => EstimateGenerationStatus::Draft->value,
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'state_version' => 1,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);
    }

    /** @return array{User, Project, EstimateGenerationSession} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => EstimateGenerationStatus::Draft->value,
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'state_version' => 1,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }
}
