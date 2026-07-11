<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
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

    private function route(Project $project, EstimateGenerationSession $session): void
    {
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_snapshot/projects/{project}/sessions/{session}', [EstimateGenerationSessionController::class, 'snapshot'])
            ->middleware(SubstituteBindings::class);
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
