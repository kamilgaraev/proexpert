<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationWorkQuantityPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_manual_equipment_count_is_a_real_bound_fact_and_replay_does_not_duplicate_it(): void
    {
        [$actor, $session] = $this->fixture();
        $input = $this->input();
        $service = app(SaveEvaluationWorkQuantity::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(1, $result['state_version']);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        self::assertSame('3', $quantity->amount);
        self::assertSame('count', $quantity->unit);
        self::assertSame('direct_quantity', $quantity->formulaKey);
        self::assertCount(2, $quantity->evidenceIds);
        self::assertSame('equipment:purchase', $quantity->formulaInputs['operands'][0]['quantity_binding']['work_item_key']);
        $item = ['key' => 'equipment:purchase', 'quantity' => '3', 'unit' => 'шт',
            'metadata' => ['quantity_key' => $result['quantity_key']], 'quantity_evidence' => $quantity->toArray()];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $changed = $this->input();
        $changed['state_version'] = 1;
        $changed['value'] = '5';
        $service->handle($actor, $scope[1], $scope[2], $changed);
        $fresh = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        self::assertSame('5', $fresh->amount);
        self::assertNotSame($quantity->evidenceIds, $fresh->evidenceIds);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft]));
    }

    public function test_count_cannot_be_reused_as_another_works_proof(): void
    {
        [$actor, $session] = $this->fixture();
        $result = app(SaveEvaluationWorkQuantity::class)->handle($actor, (int) $session->project_id, (int) $session->id, $this->input());
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        $item = ['key' => 'equipment:unrelated', 'quantity' => '3', 'unit' => 'шт',
            'metadata' => ['quantity_key' => $result['quantity_key']], 'quantity_evidence' => $quantity->toArray()];
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope,
            ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]]]));
    }

    public function test_incompatible_unit_rolls_back_both_numeric_fact_and_choice(): void
    {
        [$actor, $session] = $this->fixture();
        $input = $this->input();
        $input['unit'] = 'm2';
        try {
            app(SaveEvaluationWorkQuantity::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('An area was accepted as an equipment count.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
            self::assertArrayNotHasKey('quantity_selections', $session->fresh()->input_payload);
            self::assertSame([], app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $session->organization_id,
                (int) $session->project_id, (int) $session->id)['quantities']);
        }
    }

    private function input(): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => 'equipment:purchase',
            'value' => '3', 'unit' => 'шт', 'reason' => 'Подтверждено три единицы оборудования по выбранной комплектации'];
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $item = ['key' => 'equipment:purchase', 'name' => 'Покупка оборудования', 'unit' => 'шт', 'quantity' => null];
        $draft = ['local_estimates' => [['key' => 'equipment', 'sections' => [['work_items' => [$item]]]]]];
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal'],
            'draft_payload' => $draft]);

        return [$actor, $session];
    }
}
