<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\RecordEstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class CandidateOnlyDraftReviewPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('nativeDates')]
    public function test_universal_native_selection_keeps_pinned_resources_through_scoped_apply_and_recalculation(bool $periodOnly): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        foreach ([['Квадратный метр', 'м²', 'area'], ['Кубический метр', 'м³', 'volume'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            \App\Models\MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $catalog = $this->catalog(true);
        if ($periodOnly) {
            unset($catalog['context']['business_date']);
        }
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['finishing'],
                'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'normative',
                'regional_context' => $catalog['context']]]);
        $blockedSession = clone $session;
        $blockedSession->draft_payload = ['contingency_amount' => '1.00'];
        config(['estimate-generation.universal_enabled' => false]);
        $blocked = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Quality\UniversalEvaluationReadinessService::class)
            ->evaluate($blockedSession, ['pending_count' => 1, 'action_required_count' => 1]);
        self::assertCount(5, $blocked->blockingIssues);
        foreach ($blocked->blockingIssues as $issue) {
            self::assertSame('estimate_evaluation.readiness.'.$issue['code'], $issue['message_key']);
            self::assertNotSame($issue['message_key'], $issue['message']);
            self::assertNotSame('', $issue['message']);
        }
        config(['estimate-generation.universal_enabled' => true]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource::class)
            ->handle($actor, (int) $project->id, (int) $session->id, ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                'conditions' => ['as_of_date' => '2088-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
                    'delivery' => 'outside_scope', 'basis' => 'Контрольный расчёт прямых затрат; доставка вне выбранного состава.']]);
        $saved = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)
            ->handle($actor, (int) $project->id, (int) $session->id, 1, (string) Str::uuid(), ['entities' => [[
                'key' => 'room', 'type' => 'room', 'parameters' => [
                    'length' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement'],
                    'width' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement'],
                ],
            ]]]);
        $quantity = $saved['quantities']['floor_area'];
        $item = ['key' => 'room-floor:'.hash('sha256', 'room'), 'name' => 'Черновая подготовка пола', 'unit' => 'm2',
            'labor_hours' => '0', 'machinery_hours' => '0',
            'quantity' => '6', 'quantity_status' => 'supported', 'quantity_evidence' => $quantity,
            'quantity_basis_details' => $quantity, 'item_type' => 'priced_work', 'source_refs' => [],
            'normative_search_text' => 'Устройство цементной стяжки пола толщиной 20 мм', 'normative_rate_code' => '11-01-011-01',
            'normative_match' => ['norm_id' => $catalog['norm_id']],
            'metadata' => ['composition_work_key' => 'rough.floor', 'quantity_key' => 'rough.floor',
                'scope_entity_id' => $quantity['formula_inputs']['entity_id']]];
        $artifacts = new BuildMostEstimateDraft;
        $draft = $artifacts->seal(['generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
            'regional_context' => $catalog['context'], 'evaluation_policy' => ['selected_sections' => ['finishing']],
            'source_input_version' => 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'object_profile' => ['object_type' => 'residential'], 'local_estimates' => [['key' => 'finish_finishing', 'title' => 'Отделка', 'source_refs' => [],
                'sections' => [['key' => 'floors', 'title' => 'Полы', 'source_refs' => [], 'work_items' => [$item]]]]]]);
        $draft = $artifacts->seal((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft));
        $session->refresh()->update(['status' => 'estimate_review_required', 'draft_payload' => $draft]);
        $pin = app(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver::class)
            ->resolve($catalog['context'], [['work_item_key' => $item['key'], 'search_text' => $item['normative_search_text'], 'unit' => 'm2', 'code' => '11-01-011-01']]);
        self::assertSame('pinned', $pin['status'], json_encode($pin, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $organization->id, (int) $project->id,
            $actor, 2, $item['key'], $catalog['norm_id'], false);
        self::assertTrue($artifacts->verifyArtifact($session->fresh()->draft_payload));
        $receipt = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $project->id, (int) $session->id, 3, (string) Str::uuid());
        $row = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('6', $row['quantity']);
        self::assertSame('calculated', $row['pricing_status'], json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame('190.80', $row['total_cost']);
        self::assertSame($catalog['child_code'], $row['normative_match']['project_resource_selections'][0]['selected_resource_code']);
        self::assertSame($catalog['context']['version_key'], $row['normative_match']['project_resource_selections'][0]['price_source_version']);
        self::assertSame($catalog['context']['estimate_regional_price_version_id'], $row['price_snapshot']['version_id']);
        self::assertSame('190.80', $receipt['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('232.78', $receipt['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertSame([], $receipt['result']['scenarios'][0]['price_conditions_unresolved']);
        self::assertTrue($artifacts->verifyArtifact($session->fresh()->draft_payload));
        self::assertSame('0', $receipt['cost_units']);
        $current = $session->fresh()->draft_payload;
        $finish = [...$item, 'key' => 'floor:finish', 'name' => 'Чистовое покрытие пола',
            'normative_search_text' => 'Устройство покрытий из ламината', 'normative_rate_code' => '11-01-034-04',
            'specialization_scenario' => (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog)->issue('finish.floor', 'residential'),
            'normative_match' => ['norm_id' => $catalog['finish_norm_id']],
            'metadata' => ['composition_work_key' => 'finish.floor', 'quantity_key' => 'finish.floor',
                'scope_entity_id' => $quantity['formula_inputs']['entity_id']]];
        $ceiling = ['key' => 'ceiling:excluded', 'name' => 'Окраска потолка', 'unit' => 'm2', 'item_type' => 'priced_work',
            'quantity' => null, 'quantity_status' => 'unknown', 'source_refs' => [],
            'metadata' => ['composition_work_key' => 'finish.ceiling', 'quantity_key' => 'finish.ceiling',
                'scope_entity_id' => $quantity['formula_inputs']['entity_id']]];
        array_push($current['local_estimates'][0]['sections'][0]['work_items'], $finish, $ceiling);
        $current = $artifacts->seal((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($current));
        $session->refresh()->update(['draft_payload' => $current]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection::class)
            ->handle($actor, (int) $project->id, (int) $session->id, ['request_id' => (string) Str::uuid(), 'state_version' => 4,
                'selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
                'price_policy' => 'normative', 'selected_work_item_keys' => [$item['key'], $finish['key']],
                'scope_reason' => 'Подтверждены стяжка и покрытие пола; окраска потолка вне состава этой сметы.',
                'artifact_hash' => $current['artifact_hash']]);
        $ready = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $project->id, (int) $session->id, 5, (string) Str::uuid());
        self::assertTrue($ready['result']['can_confirm_for_apply'], json_encode([
            'requirements' => $ready['result']['missing_requirements'],
            'unpriced' => $ready['result']['scenarios'][0]['unpriced_positions'],
            'conditions' => $ready['result']['scenarios'][0]['price_conditions_unresolved'],
            'rows' => array_map(static fn (array $row): array => ['key' => $row['key'], 'status' => $row['pricing_status'] ?? null,
                'blocker' => $row['pricing_blocker'] ?? null, 'norm' => $row['normative_match'] ?? null],
                $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([$item['key'], $finish['key']], array_column($ready['result']['scenarios'][0]['positions'], 'key'));
        self::assertSame(['ceiling:excluded'], array_column($ready['result']['excluded_work_items'], 'key'));
        $this->travel(2)->seconds();
        $confirmation = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario::class)
            ->handle($actor, (int) $project->id, (int) $session->id, ['request_id' => (string) Str::uuid(), 'state_version' => 6,
                'revision_id' => $ready['revision_id'], 'content_hash' => $ready['content_hash'],
                'scenario_id' => 'base', 'assumptions_accepted' => true]);
        $this->actingAs($actor);
        $factsBeforeRead = app(ProjectModelRepository::class)->snapshotForPlanning((int) $organization->id, (int) $project->id, (int) $session->id, 10001)['token'];
        $probeItems = array_column(array_intersect_key((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope)
            ->items($session->fresh()->draft_payload), array_fill_keys([$item['key'], $finish['key']], true)), 'item');
        $conversionVersion = random_int(1000000, 1999999);
        $conversionFactor = '10.000000000000';
        $conversionFingerprint = hash('sha256', implode('|', ['10 h', 'h', $conversionFactor, (string) $conversionVersion]));
        $conversionId = DB::table('estimate_generation_unit_conversions')->insertGetId(['from_unit' => '10 h', 'to_unit' => 'h',
            'factor' => $conversionFactor, 'version' => $conversionVersion, 'fingerprint' => $conversionFingerprint,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $conversionItems = $probeItems;
        $conversionItems[0]['labor'][0] = [...$conversionItems[0]['labor'][0], 'unit' => '10 h', 'price_unit' => 'h',
            'unit_conversion_version' => $conversionVersion];
        $conversionItems[0]['labor'][0]['normative_ref'] = [...$conversionItems[0]['labor'][0]['normative_ref'],
            'unit_conversion_id' => $conversionId, 'unit_conversion_fingerprint' => $conversionFingerprint,
            'unit_conversion_factor' => $conversionFactor];
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\VerifyCurrentNormativeResourcePrices)
                ->verify(array_merge(...array_fill(0, 25, $conversionItems)), $session->fresh()->draft_payload['regional_context']));
            self::assertCount(1, array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "estimate_resource_prices"')));
            self::assertCount(1, array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "estimate_generation_unit_conversions"')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $conversionItems[0]['labor'][0]['normative_ref']['unit_conversion_fingerprint'] = str_repeat('d', 64);
        self::assertFalse((new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\VerifyCurrentNormativeResourcePrices)
            ->verify($conversionItems, $session->fresh()->draft_payload['regional_context']));
        $invalidItems = $probeItems;
        $invalidItems[0]['materials'][0]['price_id'] = PHP_INT_MAX;
        self::assertFalse((new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\VerifyCurrentNormativeResourcePrices)
            ->verify($invalidItems, $session->fresh()->draft_payload['regional_context']));
        $readySnapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session->fresh(), ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertTrue($readySnapshot->canApply, json_encode($readySnapshot->blockingIssues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertContains('apply', array_column($readySnapshot->availableActions, 'action'));
        self::assertSame('1989.58', $readySnapshot->evaluationConfirmation['total_by_currency']['RUB']);
        self::assertSame($factsBeforeRead, app(ProjectModelRepository::class)->snapshotForPlanning((int) $organization->id, (int) $project->id, (int) $session->id, 10001)['token']);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $organization->id, (int) $project->id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $source = DB::table('estimate_resource_prices')->where('resource_code', $catalog['child_code'])
            ->where('regional_price_version_id', $catalog['context']['estimate_regional_price_version_id'])->first();
        try {
            DB::transaction(fn () => DB::table('estimate_resource_prices')->where('id', $source->id)->update(['base_price' => '91.0000']));
            self::fail('Published native catalogue prices must remain immutable.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('published_pricing_catalog_is_immutable', $exception->getMessage());
            self::assertSame(0, \App\Models\Estimate::query()->where('project_id', $project->id)->count());
            self::assertSame($confirmation['state_version'], $session->fresh()->state_version);
        }
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame(2, $estimate->items()->whereNull('parent_work_id')->count());
        self::assertSame(4, $estimate->items()->whereNotNull('parent_work_id')->count());
        self::assertSame('resource', $estimate->calculation_method);
        self::assertSame(2, $estimate->items()->whereNotNull('parent_work_id')->where('item_type', 'material')->count());
        self::assertSame(2, $estimate->items()->whereNotNull('parent_work_id')->where('item_type', 'labor')->count());
        self::assertSame(0, $estimate->items()->where('name', 'Окраска потолка')->count());
        self::assertSame('1630.80', $estimate->total_amount);
        self::assertSame('1989.58', $estimate->total_amount_with_vat);
        foreach ($estimate->items()->whereNull('parent_work_id')->get() as $ordinary) {
            self::assertSame('excluded', $ordinary->metadata['generated_source_money']['money']['vat_mode']);
            self::assertSame('22', $ordinary->metadata['generated_source_money']['money']['vat_rate']);
            self::assertNotEmpty($ordinary->metadata['generated_source_money']['confirmation']['fact_id']);
        }
        app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
        self::assertSame('1989.58', $estimate->fresh()->total_amount_with_vat);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('candidateOrigins')]
    public function test_published_candidates_support_review_quantity_search_selection_and_free_strict_recalculation(bool $legacyLocation): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $catalog = $this->catalog();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['regional_context' => $catalog['context']]]);
        $base = app(SessionBaseInputVersionResolver::class)->resolve($session);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
        $candidate = ['key' => 'work:floor', 'name' => 'Устройство пола', 'item_type' => 'quantity_review', 'quantity' => null, 'unit' => 'm2',
            'total_cost' => null, 'pricing_status' => 'not_calculated', 'pricing_blocker' => 'quantity_review_required',
            'quantity_basis' => '', 'source_refs' => [], 'confidence' => 0.5, 'validation_flags' => ['quantity_review_required'],
            'metadata' => ['quantity_key' => 'floor_area'], 'normative_candidates' => [['norm_id' => $catalog['norm_id']]]];
        $other = [...$candidate, 'key' => 'work:unresolved', 'name' => 'Неподтверждённая другая работа', 'normative_candidates' => []];
        $builder = new BuildMostEstimateDraft;
        $draft = $builder->build(['title' => 'Проверка кандидатов', 'source_input_version' => $base, 'input_snapshot_hash' => $capture['token'],
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'source_version' => $base],
            'catalog_identity' => ['version' => $catalog['context']['normative_dataset_version'], 'hash' => hash('sha256', 'catalog'), 'status' => 'current'],
            'technology_identity' => ['version' => 'test:technology', 'hash' => hash('sha256', 'technology'), 'status' => 'current'],
            'rule_identity' => ['version' => 'test:rules', 'hash' => hash('sha256', 'rules'), 'status' => 'current'],
            'regional_context' => $catalog['context'], 'local_estimates' => [['key' => 'local:floor', 'title' => 'Работы на проверке',
                'source_refs' => [], 'sections' => [['key' => 'floor', 'title' => 'Полы', 'source_refs' => [], 'work_items' => [$candidate, $other]]]]]]);
        if ($legacyLocation) {
            foreach ($draft['stage6_candidate_rows'] as &$row) {
                unset($row['metadata']['stage6_review_origin']);
            }
            unset($row);
        }
        $draft = $builder->seal(app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class)->validate($draft));
        $session->update(['draft_payload' => $draft]);
        self::assertCount(2, $draft['stage6_candidate_rows']);
        $queue = app(EstimateGenerationReviewItemService::class)->forSession($session->fresh());
        $review = array_column($queue['items'], null, 'work_item_key')['work:floor'];
        self::assertSame('Устройство пола', $review['work_item']['name']);
        self::assertSame('confirm_quantity', $review['required_action']);
        self::assertSame($legacyLocation ? 'stage6-review' : 'local:floor', $review['local_estimate_key']);
        $search = app(NormativeCandidateManualSearchService::class)->search($session->fresh(), 'work:floor', 'Устройство пола');
        self::assertSame('work:floor', $search['work_item_key']);
        self::assertContains($catalog['norm_id'], array_column($search['candidates'], 'norm_id'));
        $command = ['request_id' => (string) Str::uuid(), 'feedback_type' => 'quantity_confirmation', 'work_item_key' => 'work:floor',
            'payload' => ['quantity' => '1', 'unit' => 'm2', 'quantity_basis' => 'Измеренная и подтверждённая площадь 1 м2.']];
        try {
            DB::transaction(function () use ($scope, $actor, $command): void {
                app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command);
                throw new \RuntimeException('rollback-candidate-feedback');
            });
            self::fail('The injected failure must roll back the complete review action.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback-candidate-feedback', $exception->getMessage());
        }
        self::assertSame(0, (int) $session->fresh()->state_version);
        self::assertSame([], app(ProjectModelRepository::class)->currentFacts(...$scope));
        self::assertSame(0, DB::table('estimate_generation_feedback')->where('session_id', $session->id)->count());
        $feedbackId = app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command);
        self::assertSame($feedbackId, app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command));
        $session->refresh();
        self::assertSame(1, (int) $session->state_version);
        self::assertArrayHasKey('work:floor', $session->input_payload['quantity_selections']);
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        self::assertSame(0, $session->packages()->count());
        $partialRequest = (string) Str::uuid();
        $partial = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 1, $partialRequest);
        self::assertSame('0', $partial['cost_units']);
        self::assertEquals($partial, app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 1, $partialRequest));
        $session->refresh();
        self::assertCount(2, $session->draft_payload['stage6_candidate_rows']);
        self::assertSame(0, $session->packages()->count());
        $positions = array_column($session->draft_payload['evaluation_result']['scenarios'][0]['positions'], null, 'key');
        self::assertSame('1', $positions['work:floor']['quantity']);
        self::assertNull($positions['work:unresolved']['quantity']);
        app(SelectNormativeCandidate::class)->handle($scope[2], $scope[0], $scope[1], $actor, 2, 'work:floor', $catalog['norm_id'], false);
        $session->refresh();
        self::assertSame(3, (int) $session->state_version);
        self::assertSame(0, $session->packages()->count());
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        $receipt = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid());
        self::assertSame('0', $receipt['cost_units']);
        $session->refresh();
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        self::assertCount(1, $session->draft_payload['stage6_candidate_rows']);
        self::assertSame('work:unresolved', $session->draft_payload['stage6_candidate_rows'][0]['key']);
        $accepted = [];
        foreach ($session->draft_payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($accepted, ...$section['work_items']);
            }
        }
        self::assertCount(1, $accepted);
        self::assertSame('work:floor', $accepted[0]['key']);
        self::assertSame('30.00', $accepted[0]['total_cost']);
        self::assertSame(1, $session->packages()->withCount('items')->get()->sum('items_count'));
        $quantity = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => (int) $session->state_version, 'work_item_key' => 'work:unresolved', 'value' => '2', 'unit' => 'm2',
            'reason' => 'Подтверждён только объём второй работы; расценка ещё не выбрана.']);
        self::assertSame('0', $quantity['cost_units']);
        self::assertArrayHasKey('work:unresolved', $session->fresh()->input_payload['quantity_selections']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public static function candidateOrigins(): array
    {
        return ['current_origin' => [false], 'legacy_without_location' => [true]];
    }

    public static function nativeDates(): array
    {
        return ['explicit_business_date' => [false], 'price_period_date' => [true]];
    }

    private function catalog(bool $abstract = false): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Проверенная тестовая норма', 'norm_type' => 'gesn', 'source_file' => 'recorded-test', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '11', 'name' => 'Полы', 'path' => '11/floors', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => $abstract ? '11-01-011-01' : '11-01-001-01', 'section_code' => '11', 'section_name' => 'Полы',
            'section_id' => $section, 'name' => $abstract ? 'Устройство цементной стяжки пола толщиной 20 мм' : 'Устройство пола', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Тестовый регион', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Тестовая зона', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2088)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2088, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        if ($abstract) {
            $finishNorm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '11-01-034-04',
                'section_code' => '11', 'section_name' => 'Полы', 'section_id' => $section,
                'name' => 'Устройство покрытий из ламината', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('estimate_norm_resources')->insert([
                ['estimate_norm_id' => $finishNorm, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h',
                    'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now],
                ['estimate_norm_id' => $finishNorm, 'resource_code' => $key.'-laminate', 'resource_name' => 'Ламинат', 'unit' => 'м2',
                    'quantity' => '1.050000', 'resource_type' => 'material', 'created_at' => $now, 'updated_at' => $now],
            ]);
            DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version,
                'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key.'-laminate',
                'resource_name' => 'Ламинат', 'unit' => 'м2', 'base_price' => '200.0000', 'price_type' => 'material',
                'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => '04.3.01.09',
                'resource_name' => 'Раствор готовый кладочный цементно-песчаный', 'unit' => 'м3', 'quantity' => '0.020000',
                'resource_type' => 'material', 'raw_payload' => json_encode(['source_tag' => 'AbstractResource']), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version,
                'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => '04.3.01.09-0054',
                'resource_name' => 'Раствор готовый кладочный цементно-песчаный М100', 'unit' => 'м3', 'base_price' => '90.0000',
                'price_type' => 'material', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'finish_norm_id' => $finishNorm ?? null, 'child_code' => '04.3.01.09-0054', 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            ...($abstract ? ['business_date' => '2088-10-01', 'year' => 2088, 'quarter' => 4, 'version_key' => $key] : []),
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
