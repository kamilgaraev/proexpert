<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('database')]
#[Group('postgres')]
final class EstimateGenerationSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function unchanged_snapshot_returns_empty_not_modified_response(): void
    {
        [$user, $project, $session] = $this->fixture();
        $this->route($project, $session);
        $this->actingAs($user);

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
        self::assertSame('private, no-cache, must-revalidate', $second->headers->get('Cache-Control'));
    }

    #[Test]
    public function authorization_precedes_conditional_response(): void
    {
        [, $project, $session] = $this->fixture();
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        $this->route($project, $session);
        $this->actingAs($foreign);

        $this->withHeader('If-None-Match', '*')
            ->get('/_snapshot/projects/'.$project->id.'/sessions/'.$session->id)
            ->assertForbidden();
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
        $this->assertSourceMutation($session, 'checkpoint insert', static fn () => DB::table('estimate_generation_pipeline_checkpoints')->insert([...$scope, 'generation_attempt_id' => $attemptId, 'base_input_version' => 'sha256:'.str_repeat('a', 64), 'stage' => 'understand_documents', 'input_version' => 'sha256:'.str_repeat('b', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'processing unit insert', static fn () => DB::table('estimate_generation_processing_units')->insert([...$scope, 'document_id' => $documentId, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => 'sha256:'.str_repeat('c', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSourceMutation($session, 'finalization outbox insert', static fn () => DB::table('estimate_generation_finalization_outbox')->insert([...$scope, 'generation_attempt_id' => $attemptId, 'event_type' => 'completed', 'idempotency_key' => hash('sha256', 'snapshot-matrix'), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
    }

    private function route(Project $project, EstimateGenerationSession $session): void
    {
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_snapshot/projects/{project}/sessions/{session}', [EstimateGenerationSessionController::class, 'snapshot'])
            ->middleware(SubstituteBindings::class);
    }

    private function assertSourceMutation(EstimateGenerationSession $session, string $source, callable $mutation): void
    {
        $builder = app(BuildSessionOperationalSnapshot::class);
        $before = $builder->handle($session, [])->operationalVersion;
        $mutation();
        $after = $builder->handle($session, [])->operationalVersion;

        self::assertNotSame($before, $after, $source);
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
