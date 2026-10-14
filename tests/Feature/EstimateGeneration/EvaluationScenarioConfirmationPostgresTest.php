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
    public function test_parameter_cases_recalculate_confirm_and_become_stale_after_operand_change_without_ai(): void
    {
        \Illuminate\Support\Facades\Http::fake([]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$actor, $session] = $this->fixture();
        Queue::fake();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $room = array_values(array_filter($models->snapshot(...$scope)->entities, static fn (Entity $entity): bool => $entity->id === 'room:fixture'))[0];
        foreach (['length' => '5', 'width' => '10'] as $parameter => $value) {
            $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:scenario-'.$parameter,
                ...[...$scope, $room->sourceVersion, $room->id, $parameter, $value, 'm', 0.0, 'user_assumption', 'confirmed', []]);
            $models->saveSourceModel([], [$fact], []);
            $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:scenario-'.$parameter,
                ...[...$scope, $room->sourceVersion, 'fact', $fact->id, $fact->id, 'user', (string) $actor->id,
                    'Введённое пользователем допущение для сценария, не измерение фотографии.', 1, []]), $fact);
        }
        $draft = $session->fresh()->draft_payload;
        foreach ($draft['local_estimates'][0]['sections'][0]['work_items'] as &$work) {
            $work['metadata']['quantity_key'] = $work['key'] === 'preparation' ? 'office.ceiling' : 'finish.floor';
            $work['name'] = $work['key'] === 'preparation' ? 'Отделка потолка' : 'Отделка пола';
            $work['metadata']['scope_entity_id'] = $room->id;
        }
        unset($work);
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$scope, $room->sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), [], []]));
        self::assertTrue($models->replaceCompleteness(...[...$scope, $room->sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), 'rules:v1', str_repeat('d', 64), [], []]));
        $cases = [];
        foreach (['minimum' => '5', 'base' => '6', 'maximum' => '7'] as $id => $value) {
            $cases[] = ['id' => $id, 'basis' => 'Согласованный вариант длины '.$value.' м', 'parameters' => [[
                'source_fact_id' => 'fact:scenario-length', 'source_fact_version' => 1, 'entity_id' => $room->id,
                'source_value_hash' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding::valueHash('5'),
                'unit' => 'm', 'value' => $value,
            ]]];
        }
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'parameter_cases' => $cases];
        $parameters = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationScenarioParameters::class)
            ->handle($actor, $scope[1], $scope[2], $request['state_version']);
        self::assertSame(['length', 'width'], array_column($parameters['parameters'], 'parameter'));
        self::assertSame('5', $parameters['parameters'][0]['value']);
        self::assertSame('0', $parameters['cost_units']);
        $saved = app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], $request);
        self::assertEquals($saved, app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], $request));
        self::assertSame('0', $saved['cost_units']);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $saved['state_version'], (string) Str::uuid());
        self::assertSame(['50', '60', '70'], array_map(static fn (array $scenario): mixed => array_column($scenario['positions'], 'quantity', 'key')['preparation'], $revision['result']['scenarios']),
            json_encode(array_map(static fn (array $scenario): array => ['id' => $scenario['id'], 'quantities' => array_column($scenario['positions'], 'quantity', 'key')], $revision['result']['scenarios']), JSON_THROW_ON_ERROR));
        $basePosition = array_column($revision['result']['scenarios'][1]['positions'], null, 'key')['preparation'];
        self::assertSame('floor_area', $basePosition['quantity_formula']);
        self::assertSame('user_assumption', $basePosition['quantity_parameter_recipe']['status']);
        self::assertSame('5', $models->fact(...[...$scope, 'fact:scenario-length'])->value);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'missing' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
            'tax' => $revision['result']['scenarios'][1]['tax_breakdown_unknown_positions'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $scope[1], $scope[2], $input);
        $current = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        config(['cache.default' => 'array']);
        $this->actingAs($actor);
        $session->refresh();
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            $scope[2], $scope[0], $scope[1], $confirmation['state_version'], idempotencyKey: (string) Str::uuid(),
            artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame('12000.00', $estimate->total_amount);
        $money = $estimate->only(['total_amount', 'total_amount_with_vat']);
        $width = $models->fact(...[...$scope, 'fact:scenario-width']);
        $changed = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:scenario-width-v2',
            ...[...$scope, $room->sourceVersion, $room->id, 'width', '11', 'm', 0.0, 'user_assumption', 'confirmed', [], 2, $width->id]);
        $models->saveSourceModel([], [$changed], []);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:scenario-width-v2',
            ...[...$scope, $room->sourceVersion, 'fact', $width->id, $changed->id, 'user', (string) $actor->id, 'Изменилось исходное допущение ширины.', 2, []]), $changed);
        $stale = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertTrue($stale['result_stale']);
        self::assertNull($stale['confirmed_scenario']);
        self::assertSame($current['revision_id'], $stale['revision_id']);
        self::assertSame($money, $estimate->fresh()->only(['total_amount', 'total_amount_with_vat']));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

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
        $numbers = array_values(array_filter($facts, static fn ($fact): bool => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::parameter($fact->type) === 'quantity'));
        self::assertCount(2, $numbers);
        self::assertSame(['room:fixture'], array_values(array_unique(array_column($numbers, 'entityId'))));
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
        // Cosmetic timestamps are not part of the accepted business price or its scenario identity.
        $catalog->touch();
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

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignSourceCases')]
    public function test_foreign_source_rounding_survives_confirmation_ordinary_publication_recalculation_and_export(string $mode, string $net, string $payable): void
    {
        [$actor, $session, , $input] = $this->fixture($mode, '0.03', 'USD');
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class)->handle($command);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame($net, $estimate->total_amount);
        self::assertSame($payable, $estimate->total_amount_with_vat);
        foreach ($estimate->items as $item) {
            self::assertSame('USD', $item->metadata['generated_source_money']['money']['currency_conversion']['source_snapshot']['currency']);
            self::assertSame('90', $item->metadata['generated_source_money']['money']['currency_conversion']['rate']['rub_per_unit']);
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame($net, $estimate->total_amount);
            self::assertSame($payable, $estimate->total_amount_with_vat);
            $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
                ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate);
            self::assertSame($payable, (string) \Brick\Math\BigDecimal::of((string) $export['total_amount_with_vat'])->toScale(2));
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function foreignSourceCases(): array
    {
        return [['included', '47.70', '56.70'], ['excluded', '56.70', '68.40']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reserveCases')]
    public function test_confirmed_fixed_contingency_is_preserved_as_a_separate_net_component_with_exact_payable_tax(string $mode, string $quoted,
        string $reserveNet, string $totalNet, string $payable, string $legacyPercent = '0'): void
    {
        [$actor, $session, , $input] = $this->fixture('included', '0.03', 'RUB', ['vat_mode' => $mode, 'quoted_amount' => $quoted, 'legacy_percent' => $legacyPercent]);
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        self::assertSame($payable, $session->draft_payload['evaluation_result']['scenarios'][1]['total_by_currency']['RUB']);
        self::assertCount(2, $session->draft_payload['evaluation_result']['scenarios'][1]['positions']);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class)->handle($command);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame($reserveNet, $estimate->contingency_amount);
        self::assertSame((string) \Brick\Math\BigDecimal::of($quoted)->toScale(2), $estimate->metadata['source_contingency_money']['quoted_amount']);
        self::assertSame($mode, $estimate->metadata['source_contingency_money']['vat_mode']);
        self::assertCount(2, $estimate->items);
        self::assertNotEmpty($estimate->metadata['confirmed_contingency']['fact_id']);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame($totalNet, $estimate->total_amount);
            self::assertSame($payable, $estimate->total_amount_with_vat);
            self::assertSame($reserveNet, $estimate->contingency_amount);
            $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
                ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate);
            self::assertSame($payable, (string) \Brick\Math\BigDecimal::of((string) $export['total_amount_with_vat'])->toScale(2));
            self::assertSame($reserveNet, (string) \Brick\Math\BigDecimal::of((string) $export['contingency_amount'])->toScale(2));
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function reserveCases(): array
    {
        return [['included', '120', '100.00', '100.53', '120.63'], ['excluded', '100', '100.00', '100.53', '120.63'],
            ['not_applicable', '100', '100.00', '100.53', '100.63'], ['included', '0.03', '0.03', '0.56', '0.66'],
            ['not_applicable', '0', '0.00', '0.53', '0.63', '7']];
    }

    private function fixture(string $vatMode = 'not_applicable', string $unitPrice = '100', string $currency = 'RUB', ?array $reserve = null): array
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
            'metadata' => ['currency' => $currency, 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => $vatMode, 'vat_rate' => '20', 'delivery_included' => true]]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog', 'region' => 'Москва', 'selected_sections' => ['finishing']]]);
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
        if ($currency !== 'RUB') {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationCurrencySelection::class)->handle($actor, $scope[1], $scope[2],
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'from_currency' => $currency,
                    'to_currency' => 'RUB', 'rub_per_unit' => '90', 'as_of_date' => '2026-10-12', 'rate_source_reference' => 'Курс договора']);
        }
        $definitions = [];
        foreach (['minimum' => '12', 'base' => '20', 'maximum' => '30'] as $id => $value) {
            $definitions[] = ['id' => $id, 'basis' => 'Явно заданный пользователем сценарный объём без подтверждения размеров фотографии',
                'quantities' => [['work_item_key' => 'preparation', 'value' => '1', 'unit' => 'm2'], ['work_item_key' => 'finishing', 'value' => $value, 'unit' => 'm2']]];
        }
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => $definitions]);
        if ($reserve !== null) {
            if (($reserve['legacy_percent'] ?? '0') !== '0') {
                $legacyDraft = $session->fresh()->draft_payload;
                $legacyDraft['contingency_percent'] = $reserve['legacy_percent'];
                $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal($legacyDraft)]);
            }
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationContingency::class)->handle($actor, $scope[1], $scope[2],
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'kind' => 'fixed', 'currency' => 'RUB',
                    'quoted_amount' => $reserve['quoted_amount'], 'vat_mode' => $reserve['vat_mode'], 'vat_rate' => '20',
                    'basis' => 'Подтверждённый резерв выбранного состава.']);
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['scope_confirmed']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'requirements' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
            'tax_unknown' => $revision['result']['scenarios'][1]['tax_breakdown_unknown_positions'],
            'currency_required' => $revision['result']['currency_conversion_required'],
            'prices' => array_column($revision['result']['scenarios'][1]['positions'], 'price_snapshot'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];

        return [$actor, $session->fresh(), $catalog, $input];
    }
}
