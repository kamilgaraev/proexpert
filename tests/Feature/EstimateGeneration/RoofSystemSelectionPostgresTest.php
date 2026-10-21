<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateItemMetadataFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\CurrentRoofSystemProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveManualProjectFactsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoofSystemScope;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class RoofSystemSelectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_roof_material_choice_has_current_entity_evidence_and_free_idempotent_receipt(): void
    {
        [$actor, $session] = $this->fixture();
        $outbound = count(Http::recorded());
        [$capture] = $this->capture($session);
        $questions = array_values(array_filter(app(NativeParameterBacklog::class)->current($session, $capture['snapshot'], $capture['token'])['questions'],
            static fn (array $row): bool => $row['answer']['kind'] === 'roof_system'));
        self::assertCount(2, $questions);
        self::assertSame(RoofSystemScope::SYSTEMS, array_column($questions[0]['input_contract']['choices'], 'value'));
        $question = $questions[0];
        $input = ['entities' => [['key' => 'roof-system-answer', 'type' => 'roof', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $question['answer']['native_question'], 'roof_system' => ['system' => 'concrete_tile',
                'entity_fingerprint' => $question['answer']['entity_fingerprint'], 'reason' => 'Цементно-песчаная черепица предусмотрена спецификацией покрытия.']]]];
        self::assertFalse(Validator::make(['state_version' => 2, 'request_id' => (string) Str::uuid(), ...$input],
            (new SaveManualProjectFactsRequest)->rules())->fails());
        $writer = app(SaveManualProjectFacts::class);
        $before = $session->fresh()->getRawOriginal();
        $assertions = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        foreach (['fingerprint', 'binding', 'unknown', 'bytes', 'mixed', 'extra', 'other_roof'] as $variant) {
            $invalid = $input;
            match ($variant) {
                'fingerprint' => $invalid['entities'][0]['roof_system']['entity_fingerprint'] = str_repeat('f', 64),
                'binding' => $invalid['entities'][0]['native_question']['version'] = str_repeat('f', 64),
                'unknown' => $invalid['entities'][0]['roof_system']['system'] = 'unreviewed_material',
                'bytes' => $invalid['entities'][0]['roof_system']['reason'] = str_repeat('я', 501),
                'mixed' => $invalid['entities'][0]['parameters'] = ['plan_area' => ['value' => '12', 'unit' => 'm2', 'basis' => 'input']],
                'extra' => $invalid['entities'][0]['roof_system']['automatic'] = true,
                'other_roof' => $invalid['entities'][0]['existing_entity_id'] = $questions[1]['answer']['entity_id'],
            };
            try {
                $writer->handle($actor, $session->project_id, $session->id, 2, (string) Str::uuid(), $invalid);
                self::fail('Invalid roof system accepted: '.$variant);
            } catch (ValidationException) {
                self::assertSame($before, $session->fresh()->getRawOriginal());
                self::assertSame($assertions, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
            }
        }
        $request = (string) Str::uuid();
        $result = $writer->handle($actor, $session->project_id, $session->id, 2, $request, $input);
        self::assertEquals($result, $writer->handle($actor, $session->project_id, $session->id, 2, $request, $input));
        self::assertSame('0', $result['cost_units']);
        [$capture, $decisions] = $this->capture($session->fresh());
        $systems = RoofSystemScope::select($capture['snapshot'], $decisions, []);
        self::assertCount(1, $systems);
        $selected = $systems[$question['answer']['entity_id']];
        self::assertSame('concrete_tile', $selected['system']);
        self::assertArrayNotHasKey($questions[1]['answer']['entity_id'], $systems);
        self::assertSame([], RoofSystemScope::select($capture['snapshot'],
            array_values(array_filter($decisions, static fn ($decision): bool => $decision->id !== $selected['decision_id'])), []));
        foreach (['system', 'fingerprint', 'source', 'decision', 'version', 'invalidated'] as $variant) {
            $facts = array_map(static function (Fact $fact) use ($variant): Fact {
                if ($fact->type !== 'roof_system') {
                    return $fact;
                }
                $properties = get_object_vars($fact);
                match ($variant) {
                    'system' => $properties['value']['system'] = 'metal_tile',
                    'fingerprint' => $properties['value']['entity_fingerprint'] = str_repeat('f', 64),
                    'source' => $properties['value']['target_source_version'] = 'sha256:'.str_repeat('f', 64),
                    'decision' => $properties['value']['decision_id'] = 'decision:foreign',
                    'version' => $properties['version']++,
                    'invalidated' => $properties['status'] = 'invalidated',
                };

                return new Fact(...$properties);
            }, $capture['snapshot']->facts);
            self::assertSame([], RoofSystemScope::select(new ProjectModelSnapshot($capture['snapshot']->entities, $facts,
                $capture['snapshot']->evidence, $capture['snapshot']->conflicts), $decisions, []), $variant);
        }
        self::assertCount($outbound, Http::recorded());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $session->fresh()->input_payload['qa_run_id']);
    }

    public function test_current_roof_material_controls_pinned_norm_bom_recalculation_and_applied_metadata(): void
    {
        [$actor, $session] = $this->fixture();
        $outbound = count(Http::recorded());
        [$capture] = $this->capture($session);
        $questions = array_values(array_filter(app(NativeParameterBacklog::class)->current($session, $capture['snapshot'], $capture['token'])['questions'],
            static fn (array $row): bool => $row['answer']['kind'] === 'roof_system'));
        $question = $questions[0];
        app(SaveManualProjectFacts::class)->handle($actor, $session->project_id, $session->id, 2, (string) Str::uuid(), ['entities' => [[
            'key' => 'covering', 'type' => 'roof', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $question['answer']['native_question'], 'roof_system' => ['system' => 'concrete_tile',
                'entity_fingerprint' => $question['answer']['entity_fingerprint'], 'reason' => 'Бетонная черепица принята по ведомости кровельных материалов.']]]]);
        $session->refresh();
        $item = $session->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        $source = array_values(array_filter(app(CurrentWorkEntitySources::class)->rows($session, $item),
            static fn (array $row): bool => $row['entity_id'] === $question['answer']['entity_id']))[0];
        app(BindEvaluationWorkEntity::class)->handle($actor, $session->project_id, $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => 3, 'work_item_key' => $item['key'],
            ...array_intersect_key($source, array_flip(['source_fact_id', 'source_fact_version', 'source_value_hash'])),
            'reason' => 'Покрытие относится к выбранной основной кровле, а не к отдельному навесу.',
        ]);
        app(SaveEvaluationWorkQuantity::class)->handle($actor, $session->project_id, $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => 4, 'work_item_key' => $item['key'], 'value' => '26', 'unit' => 'm2',
            'reason' => 'Сметный объём покрытия 26 м² принят по независимой ведомости объёмов работ.',
        ]);
        $catalog = $this->normativeCatalog();
        $session->refresh();
        $draft = $session->draft_payload;
        $draft['regional_context'] = $catalog['context'];
        $draft['object_profile'] = ['object_type' => 'residential'];
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['normative_match'] = ['norm_id' => $catalog['metal']];
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['specialization_scenario'] = ['normative_rate_code' => '12-01-023-01', 'material_markers' => ['металлочереп']];
        $draft = $this->recalculate($draft, $actor, $session);
        $item = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('concrete_tile', $item['metadata']['roof_system']['system']);
        self::assertSame('26', $item['quantity']);
        self::assertArrayNotHasKey('normative_match', $item);
        self::assertArrayNotHasKey('specialization_scenario', $item['metadata']);
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['status' => 'estimate_review_required', 'draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);
        app(CurrentRoofSystemProofGuard::class)->assertCurrent($session, $item);
        $search = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService::class)
            ->search($session, $item['key'], '12-01-007', 10);
        self::assertContains($catalog['concrete'], array_column($search['candidates'], 'norm_id'), json_encode($search, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertNotContains($catalog['metal'], array_column($search['candidates'], 'norm_id'));
        self::assertSame('12-01-007', $search['query']);
        $before = $session->fresh()->getRawOriginal();
        try {
            app(NormativeCandidateSelectionService::class)->select($session, $item['key'], $catalog['metal'], true);
            self::fail('Metal roofing norm was accepted for the current concrete tile choice.');
        } catch (ValidationException) {
            self::assertSame($before, $session->fresh()->getRawOriginal());
        }
        app(NormativeCandidateSelectionService::class)->select($session->fresh(), $item['key'], $catalog['concrete'], true);
        $session->refresh();
        $priced = $session->draft_payload;
        $item = $priced['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame($catalog['concrete'], $item['normative_match']['norm_id']);
        self::assertNotEmpty($item['materials']);
        self::assertStringContainsString('бетон', mb_strtolower($item['materials'][0]['name']));
        self::assertEqualsWithDelta(27.3, (float) $item['materials'][0]['quantity'], 0.000001);
        self::assertGreaterThan(0, (float) $item['total_cost']);
        $recalculated = $this->recalculate($priced, $actor, $session);
        $repeated = $recalculated['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($item['metadata']['roof_system']),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($repeated['metadata']['roof_system']));
        self::assertEquals($item['total_cost'], $repeated['total_cost']);
        self::assertSame($item['metadata']['roof_system'], (new GeneratedEstimateItemMetadataFactory)->make($item)['roof_system']);
        app(CurrentDraftQuantityProofGuard::class)->assertCurrent((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $priced);
        $evidenceId = (int) substr($item['metadata']['roof_system']['evidence_ids'][0], 9);
        app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class)->invalidate((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, [$evidenceId], 'source_replaced');
        foreach (['search', 'apply'] as $gate) {
            try {
                $gate === 'search' ? app(CurrentRoofSystemProofGuard::class)->assertCurrent($session->fresh(), $item)
                    : app(CurrentDraftQuantityProofGuard::class)->assertCurrent((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $priced);
                self::fail('Invalidated material proof passed '.$gate);
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($outbound, count(Http::recorded()));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
    }

    private function recalculate(array $draft, User $actor, EstimateGenerationSession $session): array
    {
        return DB::transaction(function () use ($draft, $actor, $session): array {
            [$capture, $decisions] = $this->capture($session);
            $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
            $context = new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                (int) $session->state_version, $version, $session->status->value, baseInputVersion: $version);

            return app(EvaluationDraftArithmeticRecalculator::class)->recalculate($draft, $context, $capture['snapshot'], $decisions, $actor, $session);
        });
    }

    private function normativeCatalog(): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Independent roof catalogue', 'norm_type' => 'gesn', 'source_file' => 'fixture', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '12', 'name' => 'Кровли', 'path' => '12/roofs', 'created_at' => $now, 'updated_at' => $now]);
        $ids = [];
        foreach ([['concrete', '12-01-007-05', 'Устройство кровель из черепицы: пазовой штампованной или прессованной (керамической и цементно-песчаной (бетонной))'],
            ['metal', '12-01-023-01', 'Устройство кровель из металлочерепицы']] as [$type, $code, $name]) {
            $ids[$type] = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => $code, 'section_code' => '12', 'section_name' => 'Кровли', 'section_id' => $section, 'name' => $name, 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $ids[$type], 'resource_code' => $key.'-labor', 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $ids['concrete'], 'resource_code' => $key.'-tile', 'resource_name' => 'Черепица бетонная', 'unit' => 'м2', 'quantity' => '1.050000', 'resource_type' => 'material', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Independent region', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Independent zone', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$key.'-labor', 'Труд рабочих', 'h', '30', 'labor'], [$key.'-tile', 'Черепица бетонная', 'м2', '200', 'material']] as [$code, $name, $unit, $price, $type]) {
            DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $code, 'resource_name' => $name, 'unit' => $unit, 'base_price' => $price, 'price_type' => $type, 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return [...$ids, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key, 'version_key' => $key, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4]];
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('canAccessInterface')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['roofing'], 'price_policy' => 'normative',
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500]]);
        $writer = app(SaveManualProjectFacts::class);
        $entities = [];
        foreach (['roof-a' => '24', 'roof-b' => '12'] as $key => $area) {
            $entities[] = ['key' => $key, 'type' => 'roof', 'name' => 'Roof '.$key,
                'parameters' => ['plan_area' => ['value' => $area, 'unit' => 'm2', 'basis' => 'measurement'],
                    'slope_run' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement'],
                    'slope_rise' => ['value' => '1', 'unit' => 'm', 'basis' => 'measurement']]];
        }
        $writer->handle($actor, $project->id, $session->id, 0, (string) Str::uuid(), ['entities' => $entities]);
        [$capture] = $this->capture($session);
        $rows = [];
        foreach (RoofSystemScope::roofs($capture['snapshot'], []) as $roof) {
            $rows[] = ['key' => $roof->stableKey, 'type' => 'roof', 'existing_entity_id' => $roof->id,
                'coverage' => ['roof_facets' => 'covered_empty', 'roof_openings' => 'covered_empty'],
                'coverage_fingerprints' => ['roof_facets' => GeometryInventory::capture($capture['snapshot'], $roof->id, 'roof_facets', [])['fingerprint'],
                    'roof_openings' => GeometryInventory::capture($capture['snapshot'], $roof->id, 'roof_openings', [])['fingerprint']]];
        }
        $writer->handle($actor, $project->id, $session->id, 1, (string) Str::uuid(), ['entities' => $rows]);
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT, 'evaluation_policy' => ['price_policy' => 'normative'],
            'local_estimates' => [['key' => 'roofing', 'title' => 'Кровельные работы', 'scope_type' => 'roof',
                'sections' => [['key' => 'roofing', 'title' => 'Покрытие кровли', 'work_items' => [[
                    'key' => 'roof-covering', 'work_key' => 'roof.covering', 'name' => 'Покрытие скатной кровли', 'item_type' => 'priced_work',
                    'quantity' => null, 'unit' => 'm2', 'metadata' => ['composition_work_key' => 'roof.covering', 'quantity_key' => 'roof.covering']]]]]]]];
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $session->refresh()->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);

        return [$actor, $session->fresh()];
    }

    private function capture(EstimateGenerationSession $session): array
    {
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);

        return [$capture, $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')])];
    }
}
