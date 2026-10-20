<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryWorkingInput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationWorkComposition;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationCompositionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveEvaluationWorkCompositionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationWorkCompositionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_preview_and_accept_append_unknown_rows_preserve_existing_work_and_replay_without_paid_calls(): void
    {
        [$actor, $session, $prefix] = $this->fixture();
        $before = $session->fresh()->draft_payload;
        $inputBefore = $session->fresh()->input_payload;
        $inputHash = app(EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $outbound = count(Http::recorded());
        $preview = $this->getJson($prefix)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertCount(11, $preview['candidates']);
        self::assertSame('Монтаж стропильных конструкций', $preview['candidates'][0]['name']);
        self::assertSame('m3', $preview['candidates'][0]['suggested_unit']);
        self::assertSame($before, $session->fresh()->draft_payload);
        $input = $this->input($preview, ['roof.rafters', 'roof.membrane', 'roof.battens', 'site.apron']);
        $result = $this->postJson($prefix, $input)->assertOk()->json('data');
        self::assertSame(2, $result['state_version']);
        self::assertSame('0', $result['cost_units']);
        self::assertTrue($result['recalculation_required']);
        self::assertTrue($result['scope_confirmation_required']);
        $saved = $session->fresh();
        $draft = $saved->draft_payload;
        self::assertSame($before['local_estimates'][0]['sections'][0]['work_items'], $draft['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame($inputBefore['quantity_selections'], $saved->input_payload['quantity_selections']);
        self::assertSame($inputBefore['price_selections'], $saved->input_payload['price_selections']);
        self::assertSame($inputBefore['manual_fact_receipts'], $saved->input_payload['manual_fact_receipts']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $saved->input_payload['qa_run_id']);
        self::assertSame(500, $saved->input_payload['qa_limit_units']);
        self::assertArrayNotHasKey('scope_selection', $saved->input_payload);
        self::assertArrayNotHasKey('scenario_selections', $saved->input_payload);
        self::assertArrayNotHasKey('confirmed_evaluation', $saved->input_payload);
        self::assertArrayNotHasKey('evaluation_result', $draft);
        self::assertFalse($draft['is_complete']);
        self::assertSame(5, $draft['evaluation_rows_count']);
        self::assertTrue(app(BuildMostEstimateDraft::class)->verifyArtifact($draft));
        self::assertNotSame($inputHash, app(EvaluationInputFingerprint::class)->fromSession($saved));
        $new = array_values(array_filter($this->items($draft), static fn (array $row): bool => in_array($row['key'], $result['added_work_item_keys'], true)));
        self::assertCount(4, $new);
        foreach ($new as $item) {
            self::assertNull($item['quantity']);
            self::assertNull($item['unit']);
            self::assertNull($item['unit_price']);
            self::assertNull($item['total_cost']);
            self::assertSame([], $item['materials']);
            self::assertSame('unknown', $item['quantity_status']);
            self::assertContains('unit_requires_confirmation', $item['validation_flags']);
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshot(...$scope);
        $proof = $saved->input_payload['composition_selections']['roof.rafters'];
        $fact = array_column($snapshot->facts, null, 'id')[$proof['fact_id']];
        $decision = array_column($models->decisionsForSelectedFacts(...[...$scope, [$fact->id]]), null, 'id')[$proof['decision_id']];
        self::assertTrue((new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision));
        self::assertSame($input['reason'], $decision->reason);
        $raw = $saved->getRawOriginal();
        $replayed = $this->postJson($prefix, $input)->assertOk()->json('data');
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($replayed));
        self::assertSame($raw, $session->fresh()->getRawOriginal());
        $next = $this->getJson($prefix)->assertOk()->json('data');
        self::assertCount(7, $next['candidates']);
        $working = FactoryWorkingInput::fromPayload($saved->input_payload);
        self::assertArrayNotHasKey('evaluation_composition_receipts', $working);
        self::assertSame($saved->input_payload['composition_selections'], $working['composition_selections']);
        foreach (['estimate_generation_ai_usage', 'estimate_generation_credit_operations', 'estimate_generation_factory_runs'] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $session->id)->count());
        }
        self::assertCount($outbound, Http::recorded());
        $quantity = app(SaveEvaluationWorkQuantity::class)->handle($actor, $session->project_id, $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => 2, 'work_item_key' => $result['added_work_item_keys'][0],
            'value' => '0.75', 'unit' => 'm3', 'reason' => 'Для сметной оценки принят условный объём древесины; это допущение.']);
        self::assertSame(3, $quantity['state_version']);
        $fact = $models->fact(...[...$scope, $quantity['source_fact_id']]);
        self::assertSame('user_assumption', $fact->origin);
        self::assertSame('0.75', $fact->value);
        self::assertSame($before['local_estimates'][0]['sections'][0]['work_items'], $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items']);
        self::assertCount($outbound, Http::recorded());
    }

    public function test_normative_composition_retains_catalog_units_and_reaches_free_quantity_recalculation(): void
    {
        [$actor, $session, $prefix] = $this->fixture();
        $payload = $session->input_payload;
        $payload['price_policy'] = 'normative';
        $draft = $session->draft_payload;
        $draft['evaluation_policy']['price_policy'] = 'mixed';
        $session->update(['input_payload' => $payload, 'draft_payload' => app(BuildMostEstimateDraft::class)->seal($draft)]);
        $outbound = count(Http::recorded());
        $preview = $this->getJson($prefix)->assertOk()->json('data');
        $input = $this->input($preview, ['roof.rafters', 'roof.battens', 'ceiling.hatch']);
        $result = $this->postJson($prefix, $input)->assertOk()->json('data');
        self::assertSame('0', $result['cost_units']);
        $added = array_values(array_filter($this->items($session->fresh()->draft_payload),
            static fn ($row): bool => in_array($row['key'], $result['added_work_item_keys'], true)));
        $byWork = array_column($added, null, 'work_key');
        foreach (['roof.rafters' => 'm3', 'roof.battens' => 'm2', 'ceiling.hatch' => 'count'] as $work => $unit) {
            self::assertSame($unit, $byWork[$work]['unit']);
            self::assertNotContains('unit_requires_confirmation', $byWork[$work]['validation_flags']);
            self::assertNull($byWork[$work]['quantity']);
            self::assertContains('quantity_review_required', $byWork[$work]['validation_flags']);
        }
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($this->postJson($prefix, $input)->assertOk()->json('data')));
        foreach (['roof.rafters' => ['0.75', 'm3'], 'ceiling.hatch' => ['1', 'count']] as $work => [$value, $unit]) {
            $receipt = app(SaveEvaluationWorkQuantity::class)->handle($actor, $session->project_id, $session->id, [
                'request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'work_item_key' => $byWork[$work]['key'], 'value' => $value, 'unit' => $unit,
                'reason' => 'Количество принято по исходному обмеру для сметного расчёта']);
            self::assertSame('0', $receipt['cost_units']);
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $recalculated = DB::transaction(function () use ($actor, $session, $scope): array {
            $current = $session->fresh();
            $models = app(ProjectModelRepository::class);
            $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
            $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
            $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($current);
            $context = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext($scope[2], $scope[0], $scope[1],
                (int) $current->state_version, $version, $current->status->value, baseInputVersion: $version);

            return app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator::class)
                ->recalculate($current->draft_payload, $context, $capture['snapshot'], $decisions, $actor, $current);
        });
        $rows = array_column($this->items($recalculated), null, 'key');
        self::assertSame('0.75', $rows[$byWork['roof.rafters']['key']]['quantity']);
        self::assertSame('1', $rows[$byWork['ceiling.hatch']['key']]['quantity']);
        self::assertNull($rows[$byWork['roof.battens']['key']]['quantity']);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $session->fresh()->input_payload['qa_run_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
        self::assertCount($outbound, Http::recorded());
    }

    public function test_current_policy_controls_unit_confirmation_before_draft_recalculation(): void
    {
        foreach (['catalog', 'mixed'] as $policy) {
            [$actor, $session, $prefix] = $this->fixture();
            $payload = $session->input_payload;
            $payload['price_policy'] = $policy;
            $draft = $session->draft_payload;
            $draft['evaluation_policy']['price_policy'] = 'normative';
            $session->update(['input_payload' => $payload, 'draft_payload' => app(BuildMostEstimateDraft::class)->seal($draft)]);
            $outbound = count(Http::recorded());
            $preview = $this->getJson($prefix)->assertOk()->json('data');
            $result = $this->postJson($prefix, $this->input($preview, ['ceiling.hatch']))->assertOk()->json('data');
            $key = $result['added_work_item_keys'][0];
            $rows = array_column($this->items($session->fresh()->draft_payload), null, 'key');
            self::assertNull($rows[$key]['unit']);
            self::assertContains('unit_requires_confirmation', $rows[$key]['validation_flags']);
            $quantity = app(SaveEvaluationWorkQuantity::class)->handle($actor, $session->project_id, $session->id, [
                'request_id' => (string) Str::uuid(), 'state_version' => 2, 'work_item_key' => $key,
                'value' => '0.36', 'unit' => 'm2', 'reason' => 'Для сметной оценки площадь люка принята 0,6 × 0,6 м как явное допущение']);
            self::assertSame('0', $quantity['cost_units']);
            $fact = app(ProjectModelRepository::class)->fact($session->organization_id, $session->project_id, $session->id, $quantity['source_fact_id']);
            self::assertSame('user_assumption', $fact->origin);
            self::assertSame('m2', $fact->unit);
            self::assertSame('0.36', $fact->value);
            self::assertCount($outbound, Http::recorded());
        }
    }

    public function test_preview_filters_selected_sections_and_does_not_reintroduce_existing_or_previously_removed_works(): void
    {
        [$actor, $session] = $this->fixture();
        $payload = $session->input_payload;
        $payload['selected_sections'] = ['roofing'];
        $payload['composition_selections']['roof.membrane'] = ['work_item_key' => 'removed-row'];
        $draft = $session->draft_payload;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['composition_work_key'] = 'roof.rafters';
        $session->input_payload = $payload;
        $session->update(['input_payload' => $payload, 'draft_payload' => $this->sealFixture($session, $draft)]);
        $preview = app(EvaluationWorkComposition::class)->show($actor, $session->project_id, $session->id);
        self::assertSame(['roof.battens', 'roof.fascia', 'roof.bargeboard'], array_column($preview['candidates'], 'work_key'));
    }

    public function test_source_changes_without_state_increment_invalidate_preview_and_foreign_organization_cannot_read_or_accept(): void
    {
        [$actor, $session] = $this->fixture();
        $composition = app(EvaluationWorkComposition::class);
        $preview = $composition->show($actor, $session->project_id, $session->id);
        $input = $this->input($preview, ['roof.rafters']);
        app(UserEvaluationChoiceWriter::class)->write($actor, $session, 'changed-source', 'evaluation_answer',
            ['answer_kind' => 'test_change'], 'Изменено основание исходных данных.', (string) Str::uuid());
        $raw = $session->fresh()->getRawOriginal();
        try {
            $composition->accept($actor, $session->project_id, $session->id, $input);
            self::fail('Stale source was accepted.');
        } catch (ValidationException) {
            self::assertSame($raw, $session->fresh()->getRawOriginal());
        }
        $otherOrganization = Organization::factory()->create();
        $actor->current_organization_id = $otherOrganization->id;
        foreach (['show', 'accept'] as $action) {
            try {
                $composition->$action($actor, $session->project_id, $session->id, ...($action === 'accept' ? [$input] : []));
                self::fail('Foreign organization accessed composition.');
            } catch (ModelNotFoundException) {
                self::assertSame($raw, $session->fresh()->getRawOriginal());
            }
        }
    }

    public function test_wrong_state_forged_work_and_changed_replay_identity_leave_draft_unchanged(): void
    {
        [$actor, $session] = $this->fixture();
        $composition = app(EvaluationWorkComposition::class);
        $preview = $composition->show($actor, $session->project_id, $session->id);
        $input = $this->input($preview, ['roof.rafters']);
        foreach ([['state_version' => 0], ['work_keys' => ['invented.work']], ['preview_fingerprint' => str_repeat('0', 64)]] as $change) {
            $raw = $session->fresh()->getRawOriginal();
            try {
                $composition->accept($actor, $session->project_id, $session->id, array_replace($input, $change));
                self::fail('Invalid composition was accepted.');
            } catch (ValidationException|StaleEstimateGenerationState) {
                self::assertSame($raw, $session->fresh()->getRawOriginal());
            }
        }
        $composition->accept($actor, $session->project_id, $session->id, $input);
        $raw = $session->fresh()->getRawOriginal();
        try {
            $composition->accept($actor, $session->project_id, $session->id, [...$input, 'reason' => 'Другое основание для прежнего запроса.']);
            self::fail('Changed replay was accepted.');
        } catch (ValidationException) {
            self::assertSame($raw, $session->fresh()->getRawOriginal());
        }
    }

    public function test_row_overflow_rolls_back_choice_and_unsealed_or_terminal_draft_cannot_be_extended(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $session->draft_payload;
        $seed = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $draft['local_estimates'][0]['sections'][0]['work_items'] = array_map(static fn (int $i): array => [...$seed, 'key' => 'old-'.$i], range(1, 1000));
        $session->update(['draft_payload' => $this->sealFixture($session, $draft)]);
        $draft = $session->fresh()->draft_payload;
        $composition = app(EvaluationWorkComposition::class);
        $preview = $composition->show($actor, $session->project_id, $session->id);
        $raw = $session->fresh()->getRawOriginal();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $token = $models->snapshotForPlanning(...[...$scope, 10001])['token'];
        try {
            $composition->accept($actor, $session->project_id, $session->id, $this->input($preview, ['roof.rafters']));
            self::fail('Overflow was accepted.');
        } catch (ValidationException) {
            self::assertSame($raw, $session->fresh()->getRawOriginal());
            self::assertSame($token, $models->snapshotForPlanning(...[...$scope, 10001])['token']);
        }
        $draft['artifact_hash'] = str_repeat('0', 64);
        $session->update(['draft_payload' => $draft]);
        try {
            $composition->show($actor, $session->project_id, $session->id);
            self::fail('Unsealed draft was offered.');
        } catch (ValidationException) {
            self::assertSame(str_repeat('0', 64), $session->fresh()->draft_payload['artifact_hash']);
        }
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal($draft), 'status' => 'failed']);
        $this->expectException(ValidationException::class);
        $composition->show($actor, $session->project_id, $session->id);
    }

    public function test_composition_reserves_facts_for_each_required_quantity_confirmation(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        app(UserEvaluationChoiceWriter::class)->write($actor, $session, 'capacity-fixture', 'evaluation_answer',
            ['answer_kind' => 'capacity_fixture'], 'Проверка предельной ёмкости модели', (string) Str::uuid());
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $bounded = static fn (int $count): array => [...$capture, 'snapshot' => new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
            $snapshot->entities, array_fill(0, $count, $snapshot->facts[0]), $snapshot->evidence, $snapshot->conflicts)];
        $modelReader = Mockery::mock(ProjectModelRepository::class);
        $modelReader->shouldReceive('snapshotForPlanning')->times(7)->with(...[...$scope, 10001])
            ->andReturn($bounded(9997), $bounded(9997), $bounded(9998), $bounded(9999), $bounded(10000), $bounded(10001), $bounded(9997));
        $preview = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationCompositionPreview($modelReader,
            app(BuildMostEstimateDraft::class), app(EvaluationInputFingerprint::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Planning\SupplementaryWorkCatalog);
        $composition = $this->app->makeWith(EvaluationWorkComposition::class, ['preview' => $preview]);
        $offered = $composition->show($actor, $session->project_id, $session->id);
        self::assertCount(11, $offered['candidates']);
        self::assertSame(1, $offered['maximum_additional_rows']);
        $raw = $session->fresh()->getRawOriginal();
        $outbound = count(Http::recorded());
        foreach (['batch', 'preview', 'accept', 'full', 'overflow'] as $action) {
            try {
                if (in_array($action, ['accept', 'batch'], true)) {
                    $composition->accept($actor, $session->project_id, $session->id,
                        $this->input($offered, $action === 'batch' ? ['roof.rafters', 'roof.battens'] : ['roof.rafters']));
                } else {
                    $composition->show($actor, $session->project_id, $session->id);
                }
                self::fail('Composition exceeded the available fact capacity: '.$action);
            } catch (ValidationException) {
                self::assertSame($raw, $session->fresh()->getRawOriginal());
                self::assertCount(1, $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
            }
        }
        $accepted = $composition->accept($actor, $session->project_id, $session->id, $this->input($offered, ['roof.rafters']));
        self::assertSame('0', $accepted['cost_units']);
        $pendingReader = Mockery::mock(ProjectModelRepository::class);
        $pendingReader->shouldReceive('snapshotForPlanning')->once()->with(...[...$scope, 10001])->andReturn($bounded(9997));
        $pendingPreview = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationCompositionPreview($pendingReader,
            app(BuildMostEstimateDraft::class), app(EvaluationInputFingerprint::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Planning\SupplementaryWorkCatalog);
        $raw = $session->fresh()->getRawOriginal();
        try {
            $pendingPreview->current($session->fresh());
            self::fail('Another composition consumed facts reserved for an outstanding quantity.');
        } catch (ValidationException) {
            self::assertSame($raw, $session->fresh()->getRawOriginal());
        }
        self::assertCount($outbound, Http::recorded());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
    }

    public function test_retained_draft_can_only_be_extended_during_evaluation_review(): void
    {
        [$actor, $session] = $this->fixture();
        $composition = app(EvaluationWorkComposition::class);
        $offered = $composition->show($actor, $session->project_id, $session->id);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $token = $models->snapshotForPlanning(...[...$scope, 10001])['token'];
        $outbound = count(Http::recorded());
        foreach (['draft', 'input_review_required', 'ready_to_generate', 'processing_documents'] as $status) {
            $session->update(['status' => $status]);
            $raw = $session->fresh()->getRawOriginal();
            foreach (['preview', 'accept'] as $action) {
                try {
                    if ($action === 'preview') {
                        $composition->show($actor, $session->project_id, $session->id);
                    } else {
                        $composition->accept($actor, $session->project_id, $session->id, $this->input($offered, ['roof.rafters']));
                    }
                    self::fail('Retained draft accepted composition outside evaluation review: '.$status.'/'.$action);
                } catch (ValidationException) {
                    self::assertSame($raw, $session->fresh()->getRawOriginal());
                    self::assertSame($token, $models->snapshotForPlanning(...[...$scope, 10001])['token']);
                }
            }
        }
        $session->update(['status' => 'ready_to_apply']);
        $ready = $composition->show($actor, $session->project_id, $session->id);
        self::assertCount(11, $ready['candidates']);
        $result = $composition->accept($actor, $session->project_id, $session->id, $this->input($ready, ['roof.rafters']));
        self::assertSame('0', $result['cost_units']);
        self::assertSame(2, $result['state_version']);
        self::assertCount($outbound, Http::recorded());
    }

    public function test_composition_reason_http_validation_matches_the_decision_byte_limit(): void
    {
        [$actor, $session, $prefix] = $this->fixture();
        $preview = $this->getJson($prefix)->assertOk()->json('data');
        $input = $this->input($preview, ['roof.rafters']);
        $rules = (new SaveEvaluationWorkCompositionRequest)->rules();
        foreach ([str_repeat('я', 500), str_repeat('a', 1000), str_repeat('😀', 250)] as $reason) {
            self::assertFalse(Validator::make([...$input, 'reason' => $reason], $rules)->fails());
        }
        $raw = $session->fresh()->getRawOriginal();
        foreach ([str_repeat('я', 501), str_repeat('a', 1001), str_repeat('😀', 251)] as $reason) {
            $validator = Validator::make([...$input, 'reason' => $reason], $rules);
            self::assertTrue($validator->fails());
            self::assertTrue($validator->errors()->has('reason'));
            $this->postJson($prefix, [...$input, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
            self::assertSame($raw, $session->fresh()->getRawOriginal());
        }
        $input['reason'] = str_repeat('я', 500);
        $result = $this->postJson($prefix, $input)->assertOk()->json('data');
        self::assertSame('0', $result['cost_units']);
        $models = app(ProjectModelRepository::class);
        $proof = $session->fresh()->input_payload['composition_selections']['roof.rafters'];
        $decisions = $models->decisionsForSelectedFacts($session->organization_id, $session->project_id, $session->id, [$proof['fact_id']]);
        self::assertSame($input['reason'], $decisions[0]->reason);
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['estimate-generation.universal_enabled' => true, 'jwt.secret' => str_repeat('test-key', 8), 'jwt.blacklist_enabled' => false]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturnTrue();
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $draft = app(BuildMostEstimateDraft::class)->seal(['generation_contract' => UniversalDraftProjector::CONTRACT,
            'local_estimates' => [['key' => 'roof', 'sections' => [['key' => 'roof', 'work_items' => [
                ['key' => 'existing-covering', 'name' => 'Кровельное покрытие', 'item_type' => 'priced_work',
                    'unit' => 'm2', 'quantity' => '56.86', 'quantity_status' => 'assumed', 'unit_price' => '123.45', 'total_cost' => '7019.37',
                    'pricing_status' => 'priced', 'materials' => [['key' => 'retained-material', 'quantity' => '62.546', 'unit_price' => '20.01']],
                    'normative_match' => ['norm_id' => 17], 'metadata' => ['composition_work_key' => 'roof.covering']],
            ]]]]], 'is_complete' => false, 'evaluation_result' => ['scenarios' => []]]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'estimate_review_required', 'state_version' => 1,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'mixed',
                'selected_sections' => ['roofing', 'finishing', 'earthworks', 'water_supply'],
                'scope_selection' => ['retained' => true], 'scenario_selections' => ['retained' => true],
                'confirmed_evaluation' => ['retained' => true], 'quantity_selections' => ['existing-covering' => ['retained' => true]],
                'price_selections' => ['existing-covering' => ['retained' => true]],
                'manual_fact_receipts' => ['retained' => ['result' => str_repeat('x', 1000)]],
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500], 'draft_payload' => $draft]);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/composition/projects/{project}/sessions/{session}/evaluation/composition';
        Route::get($route, [EstimateGenerationCompositionController::class, 'show'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::post($route, [EstimateGenerationCompositionController::class, 'accept'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        $this->withToken(auth('api_admin')->login($actor));

        return [$actor, $session, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route)];
    }

    private function input(array $preview, array $works): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $preview['state_version'],
            'preview_fingerprint' => $preview['preview_fingerprint'], 'work_keys' => $works,
            'reason' => 'По исходному чертежу дополнены пропущенные работы. Количества подтверждаются отдельно.'];
    }

    private function sealFixture(EstimateGenerationSession $session, array $draft): array
    {
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);

        return app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft));
    }

    private function items(array $draft): array
    {
        return array_merge(...array_map(static fn (array $local): array => array_merge(...array_column($local['sections'], 'work_items')), $draft['local_estimates']));
    }
}
