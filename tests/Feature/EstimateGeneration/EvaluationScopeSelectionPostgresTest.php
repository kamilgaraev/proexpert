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
    public function test_explicit_work_scope_keeps_source_rows_and_audit_history_while_projecting_only_confirmed_keys(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $this->workDraft($session);
        $session->update(['draft_payload' => $draft]);
        $request = [...$this->request(['finishing']), 'selected_work_item_keys' => ['floor:one'],
            'artifact_hash' => $draft['artifact_hash'], 'scope_reason' => 'Контрольный состав: только покрытие пола первого помещения.',
            'excluded_audit_finding_ids' => ['finding:two', 'finding:fixture']];
        $service = app(SaveEvaluationScopeSelection::class);
        $saved = $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        self::assertEquals($saved, $service->handle($actor, (int) $session->project_id, (int) $session->id, $request));
        $session->refresh();
        self::assertSame(CanonicalPipelineJson::encode($draft), CanonicalPipelineJson::encode($session->draft_payload));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft['evaluation_policy'] = [...$session->input_payload, 'scope_selection' => $session->input_payload['scope_selection']];
        $result = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build($draft, $snapshot, $decisions);
        self::assertSame(['floor:one'], array_column($result['scenarios'][0]['positions'], 'key'));
        self::assertSame(['floor:two'], array_column($result['excluded_work_items'], 'key'));
        self::assertSame(['finding:two', 'finding:fixture'], array_column($result['audit_dispositions'], 'finding_id'));
        self::assertSame(2, count(array_filter($result['missing_requirements'], static fn (array $row): bool => $row['requirement'] === 'audit_finding')));
        self::assertTrue($result['scope_confirmed']);
        self::assertFalse($result['can_confirm_for_apply']);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['scope_entity_id'] = 'entity:changed';
        $stale = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build($draft, $snapshot, $decisions);
        self::assertFalse($stale['scope_confirmed']);
        self::assertCount(2, $stale['scenarios'][0]['positions']);
        self::assertContains('work_scope_confirmation', array_column($stale['missing_requirements'], 'requirement'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_work_scope_rejects_stale_unknown_empty_and_in_scope_audit_exclusions_atomically(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $this->workDraft($session);
        $session->update(['draft_payload' => $draft]);
        $base = [...$this->request(['finishing']), 'selected_work_item_keys' => ['floor:one'],
            'artifact_hash' => $draft['artifact_hash'], 'scope_reason' => 'Подтверждённый контрольный состав первого помещения.'];
        $invalid = [
            ['selected_work_item_keys' => []], ['selected_work_item_keys' => ['floor:unknown']],
            ['selected_work_item_keys' => ['floor:one', 'floor:one']], ['artifact_hash' => str_repeat('0', 64)],
            ['excluded_audit_finding_ids' => ['finding:one']], ['excluded_audit_finding_ids' => ['finding:unkeyed']],
            ['excluded_audit_finding_ids' => ['finding:missing']], ['accepted_material_finding_ids' => ['finding:one']],
        ];
        foreach ($invalid as $change) {
            try {
                app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, [...$base, ...$change]);
                self::fail('Invalid work scope was accepted.');
            } catch (ValidationException) {
                self::assertSame(0, $session->fresh()->state_version);
                self::assertArrayNotHasKey('scope_selection', $session->fresh()->input_payload);
            }
        }
        self::assertSame([], app(ProjectModelRepository::class)->currentFacts((int) $session->organization_id, (int) $session->project_id, (int) $session->id));
        self::assertSame(CanonicalPipelineJson::encode($draft), CanonicalPipelineJson::encode($session->fresh()->draft_payload));
    }

    public function test_material_review_requires_an_explicit_current_norm_choice_and_cannot_survive_its_change(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $this->workDraft($session);
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $item['normative_match'] = ['norm_id' => 101, 'code' => '11-01-034-04', 'selected_by_user' => true, 'hard_gate_passed' => true];
        $item['specialization_scenario'] = ['signature' => str_repeat('a', 64), 'assumption_code' => 'laminate', 'normative_rate_code' => '11-01-034-04'];
        $item['metadata']['material_assumption'] = ['code' => 'laminate', 'message' => 'Ламинат принят для контрольного состава.'];
        unset($item);
        $draft['audit_review_items'][2]['type'] = 'unjustified_expense';
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->seal($draft);
        $session->update(['draft_payload' => $draft]);
        $request = [...$this->request(['finishing']), 'selected_work_item_keys' => ['floor:one'],
            'artifact_hash' => $draft['artifact_hash'], 'scope_reason' => 'Ламинат подтверждён как материал первого помещения.',
            'accepted_material_finding_ids' => ['finding:one']];
        app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        $session->refresh();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft['evaluation_policy'] = $session->input_payload;
        $builder = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
        $result = $builder->build($draft, $snapshot, $decisions);
        self::assertSame(['material_assumption_accepted'], array_column($result['audit_dispositions'], 'status'));
        self::assertNotEmpty($result['scenarios'][0]['assumptions']);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['normative_match']['norm_id'] = 102;
        $changed = $builder->build($draft, $snapshot, $decisions);
        self::assertSame([], $changed['audit_dispositions']);
        self::assertContains('Проверить включённую работу.', array_column($changed['missing_requirements'], 'reason'));
        self::assertFalse($changed['can_confirm_for_apply']);
    }

    private function workDraft(EstimateGenerationSession $session): array
    {
        $item = ['key' => 'floor:one', 'name' => 'Первое помещение', 'unit' => 'm2', 'quantity' => '6',
            'quantity_status' => 'supported', 'metadata' => ['composition_work_key' => 'finish.floor']];
        $draft = ['generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session),
            'local_estimates' => [['key' => 'finish_finishing', 'sections' => [['key' => 'floors', 'work_items' => [
                $item, [...$item, 'key' => 'floor:two', 'name' => 'Второе помещение', 'quantity' => null, 'quantity_status' => 'unknown'],
            ]]]]], 'audit_review_items' => [
                ['finding_id' => 'finding:two', 'item_key' => 'floor:two', 'type' => 'quantity_mismatch', 'reason' => 'Нет объёма второго помещения.'],
                ['finding_id' => 'finding:fixture', 'item_key' => 'fixture:missing', 'type' => 'omission', 'reason' => 'Нет сантехнического оборудования.'],
                ['finding_id' => 'finding:one', 'item_key' => 'floor:one', 'type' => 'quantity_mismatch', 'reason' => 'Проверить включённую работу.'],
                ['finding_id' => 'finding:unkeyed', 'type' => 'omission', 'reason' => 'Общий вопрос к исходным данным.'],
            ]];

        return (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)
            ->seal((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft));
    }

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
        $project = Project::factory()->for($organization)->createQuietly();
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
