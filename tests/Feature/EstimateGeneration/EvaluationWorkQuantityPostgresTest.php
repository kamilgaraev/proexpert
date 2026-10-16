<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationEvaluationController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationWorkQuantityPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_http_manual_quantity_validation_reaches_the_writer_and_replays_without_new_facts(): void
    {
        [$actor, $session] = $this->fixture();
        $url = $this->quantityEndpoint($actor, $session);
        $input = $this->input();
        $response = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.state_version', 1)->assertJsonPath('data.cost_units', '0');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.fact_id', $response->json('data.fact_id'));
        self::assertSame(1, (int) $session->fresh()->state_version);
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id)['quantities'][$response->json('data.quantity_key')];
        self::assertSame('3', $quantity->amount);
        self::assertSame('count', $quantity->unit);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_http_quantity_rejects_a_native_source_combined_with_either_manual_field(): void
    {
        [$actor, $session] = $this->fixture();
        $url = $this->quantityEndpoint($actor, $session);
        foreach ([['value' => '3'], ['unit' => 'шт'], ['value' => '3', 'unit' => 'шт']] as $manual) {
            $input = $this->input();
            unset($input['value'], $input['unit']);
            $this->postJson($url, [...$input, 'source_fact_id' => 'fact:source', 'source_fact_version' => 1,
                'source_value_hash' => str_repeat('a', 64), ...$manual])->assertUnprocessable()->assertJsonValidationErrors(array_keys($manual));
            self::assertSame(0, (int) $session->fresh()->state_version);
            self::assertArrayNotHasKey('quantity_selections', $session->fresh()->input_payload);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_manual_work_quantity_preserves_the_existing_asset_and_does_not_replace_another_works_fact(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $manual = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)
            ->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
                ['key' => 'pump-1', 'type' => 'equipment', 'parameters' => ['equipment_count' => ['value' => '3', 'unit' => 'count', 'basis' => 'input']]],
            ]]);
        $target = $manual['quantities']['equipment.count']['formula_inputs']['entity_id'];
        $draft = $session->fresh()->draft_payload;
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $item['metadata'] = ['scope_entity_id' => $target];
        $other = [...$item, 'key' => 'equipment:install'];
        unset($item);
        $draft['local_estimates'][0]['sections'][0]['work_items'][] = $other;
        $draft['source_input_version'] = 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($draft);
        $session->update(['draft_payload' => $draft]);
        $input = [...$this->input(), 'state_version' => 1, 'value' => '4'];
        $service = app(SaveEvaluationWorkQuantity::class);
        $result = $service->handle($actor, $scope[1], $scope[2], $input);
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        self::assertSame($target, $quantity->formulaInputs['entity_id']);
        self::assertSame('4', $quantity->amount);
        $item = ['key' => 'equipment:purchase', 'quantity' => '4', 'unit' => 'шт',
            'metadata' => ['quantity_key' => $result['quantity_key'], 'scope_entity_id' => $target], 'quantity_evidence' => $quantity->toArray()];
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope,
            ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]]]));
        self::addToAssertionCount(1);
        $service->handle($actor, $scope[1], $scope[2], [...$this->input(), 'state_version' => 2,
            'work_item_key' => 'equipment:install', 'value' => '2']);
        $fresh = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        self::assertSame('4', $fresh->amount);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

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

    public function test_unknown_commercial_unit_is_confirmed_by_the_bound_user_choice_and_replays_without_duplicate_facts(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $session->draft_payload;
        $draft['generation_contract'] = UniversalDraftProjector::CONTRACT;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['unit'] = null;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['validation_flags'] = ['unit_requires_confirmation'];
        $artifacts = app(BuildMostEstimateDraft::class);
        $session->input_payload = [...$session->input_payload, 'price_policy' => 'catalog'];
        $draft['source_input_version'] = 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session);
        $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($draft);
        $session->update(['input_payload' => $session->input_payload, 'draft_payload' => $artifacts->seal($draft)]);
        $service = app(SaveEvaluationWorkQuantity::class);
        $input = [...$this->input(), 'unit' => '100 м2'];
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $fresh = $session->fresh();
        $item = $fresh->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('100 м2', $item['unit']);
        self::assertNotContains('unit_requires_confirmation', $item['validation_flags']);
        self::assertSame($result['fact_id'], $item['metadata']['unit_confirmation']['fact_id']);
        self::assertSame('100 м2', $fresh->input_payload['quantity_selections'][$item['key']]['confirmed_work_unit']);
        self::assertTrue($artifacts->verifyArtifact($fresh->draft_payload));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$result['quantity_key']];
        self::assertSame('300', $quantity->amount);
        self::assertSame('m2', $quantity->unit);
        self::assertNotEmpty($quantity->assumptions);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_unknown_unit_does_not_override_a_selected_normative_or_bypass_an_unsigned_draft(): void
    {
        foreach (['normative_policy', 'selected_norm', 'unsigned', 'missing_confirmation_flag'] as $case) {
            [$actor, $session] = $this->fixture();
            $draft = $session->draft_payload;
            $draft['generation_contract'] = UniversalDraftProjector::CONTRACT;
            $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
            $item['unit'] = null;
            $item['validation_flags'] = $case === 'missing_confirmation_flag' ? [] : ['unit_requires_confirmation'];
            if ($case === 'selected_norm') {
                $item['normative_match']['norm_id'] = 1;
            }
            unset($item);
            $session->input_payload = [...$session->input_payload, 'price_policy' => $case === 'normative_policy' ? 'normative' : 'catalog'];
            $draft['source_input_version'] = 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session);
            $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($draft);
            $draft = app(BuildMostEstimateDraft::class)->seal($draft);
            if ($case === 'unsigned') {
                unset($draft['artifact_hash']);
            }
            $session->update(['input_payload' => $session->input_payload,
                'draft_payload' => $draft]);
            try {
                app(SaveEvaluationWorkQuantity::class)->handle($actor, (int) $session->project_id, (int) $session->id, [...$this->input(), 'unit' => 'm2']);
                self::fail('Invalid unit confirmation was accepted: '.$case);
            } catch (ValidationException $error) {
                self::assertArrayHasKey('unit', $error->errors());
                self::assertSame(0, (int) $session->fresh()->state_version);
                self::assertArrayNotHasKey('quantity_selections', $session->fresh()->input_payload);
            }
        }
    }

    private function input(): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => 'equipment:purchase',
            'value' => '3', 'unit' => 'шт', 'reason' => 'Подтверждено три единицы оборудования по выбранной комплектации'];
    }

    private function quantityEndpoint(User $actor, EstimateGenerationSession $session): string
    {
        $project = Project::query()->findOrFail($session->project_id);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        Route::post('/_contract/projects/{project}/estimate-generation/sessions/{session}/evaluation/quantity',
            [EstimateGenerationEvaluationController::class, 'quantity'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        $this->withToken(auth('api_admin')->login($actor));

        return '/_contract/projects/'.$project->id.'/estimate-generation/sessions/'.$session->id.'/evaluation/quantity';
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
