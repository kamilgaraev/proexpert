<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScopeSelectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_scope_is_confirmed_by_a_real_choice_proof_and_replay_preserves_the_original_receipt(): void
    {
        [$actor, $session] = $this->fixture();
        $request = $this->request(['equipment', 'automation']);
        $service = app(SaveEvaluationScopeSelection::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($service->handle($actor, (int) $session->project_id, (int) $session->id, $request)));
        $session->refresh();
        self::assertSame(['automation', 'equipment'], $session->input_payload['selected_sections']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(1, $session->state_version);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $expected = ['selected_sections' => ['automation', 'equipment'], 'profile_id' => 'industrial', 'construction_type' => 'reconstruction',
            'price_policy' => 'mixed', 'profile_version' => ScopeCompletenessEvaluator::VERSION];
        self::assertTrue((new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $session->input_payload['scope_selection'], 'evaluation_scope', $expected));
        $expected['selected_sections'] = ['finishing'];
        self::assertFalse((new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $session->input_payload['scope_selection'], 'evaluation_scope', $expected));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_unknown_sections_and_stale_changes_are_rejected_without_saving_a_fact(): void
    {
        [$actor, $session] = $this->fixture();
        $service = app(SaveEvaluationScopeSelection::class);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id, $this->request(['unsupported_process']));
            self::fail('Unknown scope was accepted.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        $request = $this->request(['equipment']);
        $request['state_version'] = 1;
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
            self::fail('Stale scope was accepted.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        self::assertSame([], app(ProjectModelRepository::class)->currentFacts((int) $session->organization_id, (int) $session->project_id, (int) $session->id));
    }

    private function request(array $sections): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'selected_sections' => $sections,
            'profile_id' => 'industrial', 'construction_type' => 'reconstruction', 'price_policy' => 'mixed'];
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft',
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['finishing']]]);

        return [$actor, $session];
    }
}
