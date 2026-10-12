<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScenarioConfirmationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_confirmation_is_a_real_free_choice_of_one_current_scenario_and_replays_once(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        Queue::fake();
        $service = app(ConfirmEvaluationScenario::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        self::assertSame('0', $result['cost_units']);
        $current = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        self::assertNotSame($input['revision_id'], $current['revision_id']);
        self::assertSame($current['revision_id'], $result['result_revision_id']);
        self::assertSame(['RUB' => '2100.00'], $current['confirmed_scenario']['total_by_currency']);
        self::assertNotEmpty($current['confirmed_scenario']['assumptions']);
        $payload = $session->fresh()->input_payload;
        $forged = $payload;
        $forged['confirmed_evaluation']['scenario_id'] = 'maximum';
        $session->update(['input_payload' => $forged]);
        self::assertNull(app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id)['confirmed_scenario']);
        $session->update(['input_payload' => $payload]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $facts = app(ProjectModelRepository::class)->snapshot(...$scope)->facts;
        self::assertCount(1, array_filter($facts, static fn ($fact): bool => $fact->type === 'evaluation_scenario' && ($fact->value['answer_kind'] ?? null) === 'selected_scenario'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
        $item = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('1', $item['quantity']);
        self::assertSame('assumed', $item['quantity_status']);
        self::assertSame('direct_quantity', $item['quantity_basis_details']['formula_key']);
        $numbers = array_values(array_filter($facts, static fn ($fact): bool => $fact->type === 'quantity'));
        self::assertCount(2, $numbers);
        self::assertSame(['user_assumption'], array_values(array_unique(array_column($numbers, 'origin'))));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class)
            ->assertCurrent(...[...$scope, $session->fresh()->draft_payload]));
        Queue::assertNothingPushed();
        $catalog->update(['unit_price' => '200']);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$input, 'revision_id' => $current['revision_id'], 'content_hash' => $current['content_hash'],
                    'request_id' => (string) Str::uuid(), 'state_version' => $result['state_version']]);
            self::fail('Changed catalog price was silently confirmed.');
        } catch (ValidationException) {
            self::assertSame($result['state_version'], $session->fresh()->state_version);
        }
        $session->update(['input_payload' => [...$payload, 'description' => 'Новый состав после подтверждения']]);
        $stale = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertTrue($stale['result_stale']);
        self::assertNull($stale['confirmed_scenario']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmationDenials')]
    public function test_confirmation_rejects_unaccepted_assumptions_old_revisions_and_changed_scope(string $change): void
    {
        [$actor, $session, , $input] = $this->fixture();
        if ($change === 'assumptions') {
            $input['assumptions_accepted'] = false;
        } elseif ($change === 'revision') {
            $input['revision_id'] = (string) Str::uuid();
        } elseif ($change === 'scope') {
            $session->update(['input_payload' => [...$session->input_payload, 'selected_sections' => ['roofing']]]);
        } elseif ($change === 'actor') {
            $actor->update(['is_active' => false]);
        }
        $before = $session->fresh()->state_version;
        try {
            app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('An invalid scenario confirmation was accepted.');
        } catch (ValidationException|\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame($before, $session->fresh()->state_version);
            self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        }
    }

    public static function confirmationDenials(): array
    {
        return array_map(static fn (string $change): array => [$change], ['assumptions', 'revision', 'scope', 'actor']);
    }

    public function test_final_confirmation_remains_verifiable_without_changing_numerical_snapshot_but_quantity_inputs_change_it(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $before = $models->snapshotForPlanning(...[...$scope, 10001]);
        $row = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        $selected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario)->select($row['result'], 'base');
        $choice = ['answer_kind' => 'selected_scenario', 'revision_id' => $input['revision_id'], 'content_hash' => $input['content_hash'],
            'input_hash' => $row['input_hash'], 'scenario_id' => 'base', 'scenario_hash' => $selected['scenario_hash'],
            'selected_scope' => ['finishing'], 'assumptions_accepted' => true];
        $proof = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter::class)
            ->write($actor, $session, 'selected-scenario', 'evaluation_scenario', $choice, 'Принят показанный сценарий.', (string) Str::uuid());
        $after = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertSame($before['token'], $after['token']);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($after['snapshot']->facts, 'id')]);
        self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier)
            ->accepted($after['snapshot'], $decisions, [...$choice, ...$proof], 'evaluation_scenario', $choice));
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserWorkQuantityFactWriter::class)
            ->write($actor, $session, 'finishing', '30', 'm2', 'Новое явное допущение объёма.', (string) Str::uuid(), true);
        self::assertNotSame($after['token'], $models->snapshotForPlanning(...[...$scope, 10001])['token']);
    }

    public function test_confirmed_catalog_scenario_creates_one_ordinary_estimate_with_identical_payable_money(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture('included', '0.03');
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        self::assertSame('ready_to_apply', $session->status->value);
        $this->actingAs($actor);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session, ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertTrue($snapshot->canApply, json_encode($snapshot->blockingIssues));
        self::assertContains('apply', array_column($snapshot->availableActions, 'action'));
        self::assertSame('review', $snapshot->recommendedStep);
        self::assertSame('0.63', $snapshot->evaluationConfirmation['total_by_currency']['RUB']);
        self::assertSame('0.63', $snapshot->estimateSummary['total_cost']);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame('analog', $estimate->calculation_method);
        self::assertSame('0.53', $estimate->total_amount);
        self::assertSame('0.63', $estimate->total_amount_with_vat);
        self::assertSame(2, $estimate->items()->whereNull('parent_work_id')->count());
        self::assertSame(0, $estimate->items()->whereNotNull('normative_rate_id')->count());
        foreach ($estimate->items as $item) {
            self::assertNotEmpty($item->metadata['generated_source_money']['confirmation']['fact_id']);
            self::assertSame('included', $item->metadata['generated_source_money']['money']['vat_mode']);
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame('0.63', $estimate->fresh()->total_amount_with_vat);
        }
        $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
            ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate->fresh());
        self::assertSame('0.63', (string) $export['total_amount_with_vat']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_catalog_change_revokes_read_only_confirmation_and_the_apply_action_without_changing_the_revision(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $this->actingAs($actor);
        $session->refresh();
        $originalState = $session->state_version;
        $originalRevisionCount = DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count();
        $catalog->update(['unit_price' => '200']);
        $evaluation = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertNull($evaluation['confirmed_scenario']);
        self::assertTrue($evaluation['result_stale']);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session->fresh(), ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertFalse($snapshot->canApply);
        self::assertNotContains('apply', array_column($snapshot->availableActions, 'action'));
        self::assertSame($originalState, $session->fresh()->state_version);
        self::assertSame($originalRevisionCount, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function fixture(string $vatMode = 'not_applicable', string $unitPrice = '100'): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Квадратный метр', 'short_name' => 'м²', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Работы по полу', 'code' => 'P-'.Str::random(6),
            'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => $unitPrice, 'is_active' => true, 'created_by_user_id' => $actor->id,
            'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => $vatMode, 'vat_rate' => '20', 'delivery_included' => true]]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog', 'selected_sections' => ['finishing']]]);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $entity = new Entity('room:fixture', ...[...$scope, 'sha256:'.hash('sha256', 'scope-without-photo-dimensions'), 'room', 'room:fixture',
            ['semantic_type' => 'room', 'identity' => ['room_name' => 'Комната']]]);
        $roomName = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:room-name',
            ...[...$scope, $entity->sourceVersion, $entity->id, 'room_name', 'Комната', null, 0.0, 'user_assumption', 'confirmed', []]);
        $models->saveSourceModel([$entity], [$roomName], []);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:room-name',
            ...[...$scope, $entity->sourceVersion, 'fact', $roomName->id, $roomName->id, 'user', (string) $actor->id,
                'Пользователь задаёт помещение сценария без утверждения его размеров.', 1, []]), $roomName);
        $items = [];
        foreach (['preparation', 'finishing'] as $key) {
            $items[] = ['key' => $key, 'name' => $key, 'work_key' => $key, 'item_type' => 'priced_work', 'unit' => 'm2',
                'quantity' => null, 'quantity_status' => 'unknown', 'metadata' => ['entity_id' => $entity->id]];
        }
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog'],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'floor', 'work_items' => $items]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $models->snapshot(...$scope));
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        app(SaveEvaluationScopeSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(), 'state_version' => 0,
            'selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        $source = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
        foreach (['preparation', 'finishing'] as $key) {
            app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'work_item_key' => $key,
                'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']]);
        }
        $definitions = [];
        foreach (['minimum' => '12', 'base' => '20', 'maximum' => '30'] as $id => $value) {
            $definitions[] = ['id' => $id, 'basis' => 'Явно заданный пользователем сценарный объём без подтверждения размеров фотографии',
                'quantities' => [['work_item_key' => 'preparation', 'value' => '1', 'unit' => 'm2'], ['work_item_key' => 'finishing', 'value' => $value, 'unit' => 'm2']]];
        }
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => $definitions]);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['scope_confirmed']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'requirements' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];

        return [$actor, $session->fresh(), $catalog, $input];
    }
}
