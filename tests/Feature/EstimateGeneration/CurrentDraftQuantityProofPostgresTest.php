<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class CurrentDraftQuantityProofPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_apply_basis_is_recomputed_and_foreign_recipes_or_replaced_facts_cannot_reuse_the_old_volume(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $input = ['entities' => [['key' => 'room-1', 'type' => 'room', 'parameters' => ['area' => ['value' => '20', 'unit' => 'm2', 'basis' => 'measurement']]]]];
        $saved = app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(), $input);
        $basis = $saved['quantities']['floor_area'];
        $item = ['key' => 'floor:1', 'quantity' => '20', 'unit' => 'm2', 'metadata' => ['quantity_key' => 'floor_area'], 'quantity_evidence' => $basis];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];
        $guard = app(CurrentDraftQuantityProofGuard::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $wrong = $draft;
        $wrong['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_evidence']['formula_key'] = 'invented_recipe';
        $this->assertRejected($guard, $scope, $wrong);
        $wrong = $draft;
        $wrong['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_evidence']['evidence_ids'] = ['source-ref:'.str_repeat('a', 64)];
        $this->assertRejected($guard, $scope, $wrong);
        $input['entities'][0]['parameters']['area']['value'] = '25';
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $input);
        $this->assertRejected($guard, $scope, $draft);
    }

    private function assertRejected(CurrentDraftQuantityProofGuard $guard, array $scope, array $draft): void
    {
        try {
            DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
            self::fail('An unsupported quantity basis was accepted.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
    }
}
