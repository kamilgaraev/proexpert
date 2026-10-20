<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Export\ReadConfirmedEvaluationExport;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationResourcePriceController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\AcceptedResourceCataloguePrices;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\Estimate;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ResourcePriceSelectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_one_material_quote_preserves_native_consumption_and_sibling_resources_through_apply_and_post_apply_xlsx(): void
    {
        [$actor, $session, $quote, $url] = $this->fixture();
        $before = $session->fresh()->draft_payload;
        $oldRough = $this->rows($before)['rough-floor'];
        $input = $this->choice($url, $session, $quote);
        $save = $this->postJson($url.'/resource-price', $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertSame('0', $save['cost_units']);
        self::assertFalse($save['estimate_complete']);
        self::assertSame($before, $session->fresh()->draft_payload);
        $raw = $session->fresh()->getRawOriginal();
        self::assertSame(CanonicalPipelineJson::encode($save), CanonicalPipelineJson::encode($this->postJson($url.'/resource-price', $input)->assertOk()->json('data')));
        self::assertSame($raw, $session->fresh()->getRawOriginal());
        $result = $this->recalculate($actor, $session);
        $rows = $this->rows($session->fresh()->draft_payload);
        self::assertSame('mixed_resource_aggregate', $rows['rough-floor']['price_snapshot']['source_type']);
        self::assertSame('192.00', $rows['rough-floor']['total_cost'], json_encode($rows['rough-floor'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame('1440.00', $rows['finish-floor']['total_cost']);
        self::assertSame(CanonicalPipelineJson::encode($oldRough['labor']), CanonicalPipelineJson::encode($rows['rough-floor']['labor']));
        self::assertSame(CanonicalPipelineJson::encode($this->rows($before)['finish-floor']['materials']), CanonicalPipelineJson::encode($rows['finish-floor']['materials']));
        $material = $rows['rough-floor']['materials'][0];
        foreach (['norm_id', 'norm_resource_id', 'norm_source_resource_id', 'resource_code', 'price_id'] as $field) {
            self::assertSame($oldRough['materials'][0]['normative_ref'][$field], $material['normative_ref'][$field]);
        }
        self::assertSame($oldRough['materials'][0]['quantity_per_unit'], $material['quantity_per_unit']);
        self::assertTrue(\Brick\Math\BigDecimal::of($material['quantity_per_unit'])->isEqualTo('0.02'));
        self::assertSame('0.12', $material['quantity']);
        self::assertSame('100.0000', $material['unit_price']);
        self::assertSame('12.00', $material['total_price']);
        self::assertSame($save['fact_id'], $material['normative_ref']['catalog_price_selection']['fact_id']);
        self::assertSame('catalog:'.$quote->id, $material['normative_ref']['catalog_price_selection']['source_snapshot']['source_reference']);
        self::assertTrue(\Brick\Math\BigDecimal::of($material['normative_ref']['catalog_price_selection']['source_snapshot']['unit_price'])->isEqualTo('119'));
        app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => $result['state_version'], 'selected_sections' => ['finishing'],
            'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'mixed',
            'selected_work_item_keys' => ['rough-floor', 'finish-floor'], 'scope_reason' => 'Проверяется только подготовка и покрытие пола; прочие работы дома не входят в этот тест.',
            'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
        ]);
        $ready = $this->recalculate($actor, $session);
        self::assertTrue($ready['result']['can_confirm_for_apply'], json_encode($ready['result']['missing_requirements'], JSON_UNESCAPED_UNICODE));
        $this->travel(2)->seconds();
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => $ready['state_version'], 'revision_id' => $ready['revision_id'],
            'content_hash' => $ready['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true,
        ]);
        $this->actingAs($actor);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session->fresh(), ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertTrue($snapshot->canApply);
        $this->assertExport($actor, $session, (int) $quote->id, $save['fact_id']);
        $command = new ApplyGeneratedEstimateCommand((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            $confirmation['state_version'], idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $ordinary = Estimate::query()->findOrFail($published->estimateId);
        self::assertSame(2, $ordinary->items()->whereNull('parent_work_id')->count());
        self::assertSame(4, $ordinary->items()->whereNotNull('parent_work_id')->count());
        self::assertSame('1632.00', $ordinary->total_amount);
        self::assertSame('1991.04', $ordinary->total_amount_with_vat);
        app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($ordinary);
        self::assertSame('1991.04', $ordinary->fresh()->total_amount_with_vat);
        $this->assertExport($actor, $session->fresh(), (int) $quote->id, $save['fact_id']);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $session->fresh()->input_payload['qa_run_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public function test_changed_product_metadata_invalidates_only_its_material_price_and_keeps_the_takeoff(): void
    {
        [$actor, $session, $quote, $url] = $this->fixture();
        $this->postJson($url.'/resource-price', $this->choice($url, $session, $quote))->assertOk();
        $this->recalculate($actor, $session);
        $old = $this->rows($session->fresh()->draft_payload);
        $quote->update(['metadata' => [...$quote->metadata, 'product_specification' => ['grade' => 'different']]]);
        self::assertFalse(app(AcceptedResourceCataloguePrices::class)->frozenMatches($session->fresh(), array_values($old)));
        $this->recalculate($actor, $session);
        $rows = $this->rows($session->fresh()->draft_payload);
        self::assertNull($rows['rough-floor']['total_cost']);
        self::assertSame('selected_resource_price_source_changed', $rows['rough-floor']['pricing_blocker']);
        self::assertSame($old['rough-floor']['quantity'], $rows['rough-floor']['quantity']);
        self::assertSame($old['rough-floor']['materials'][0]['quantity'], $rows['rough-floor']['materials'][0]['quantity']);
        self::assertSame($old['rough-floor']['materials'][0]['quantity_per_unit'], $rows['rough-floor']['materials'][0]['quantity_per_unit']);
        self::assertSame('1440.00', $rows['finish-floor']['total_cost']);
        $resources = $this->getJson($url.'/resources?work_item_key=rough-floor')->assertOk()->json('data.resources');
        self::assertSame('review_required', $resources[0]['selection_status']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_invalidated_real_choice_evidence_cannot_be_replaced_by_a_payload_pointer(): void
    {
        [$actor, $session, $quote, $url] = $this->fixture();
        $save = $this->postJson($url.'/resource-price', $this->choice($url, $session, $quote))->assertOk()->json('data');
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshot(...[...$scope, 10001]);
        $fact = array_column($snapshot->facts, null, 'id')[$save['fact_id']];
        app(EvidenceRepository::class)->invalidate(...[...$scope, array_map(static fn (string $id): int => (int) substr($id, 9), $fact->evidenceIds), 'source_changed']);
        $this->recalculate($actor, $session);
        self::assertNull($this->rows($session->fresh()->draft_payload)['rough-floor']['total_cost']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_request_guards_preserve_the_budget_and_reject_stale_targets_or_missing_product_acceptance(): void
    {
        [$actor, $session, $quote, $url] = $this->fixture();
        $input = $this->choice($url, $session, $quote);
        $before = $session->fresh()->getRawOriginal();
        foreach ([['same_product_confirmed' => false], ['target_hash' => str_repeat('f', 64)], ['source_hash' => str_repeat('e', 64)],
            ['reason' => ''], ['qa_limit_units' => 1000], ['source_reference' => 'work_type:1'], ['current_interface' => 'admin']] as $patch) {
            $this->postJson($url.'/resource-price', [...$input, ...$patch])->assertUnprocessable();
            self::assertSame($before, $session->fresh()->getRawOriginal());
        }
        $this->postJson($url.'/resource-price', [...$input, 'state_version' => 0])->assertConflict();
        self::assertSame($before, $session->fresh()->getRawOriginal());
    }

    public function test_catalogue_prices_and_resource_targets_remain_isolated_by_organization(): void
    {
        [$actor, $session, $quote, $url] = $this->fixture();
        $input = $this->choice($url, $session, $quote);
        $otherOrganization = Organization::factory()->create();
        $foreignUnit = MeasurementUnit::query()->create(['organization_id' => $otherOrganization->id,
            'name' => 'Кубический метр', 'short_name' => 'м³', 'type' => 'volume']);
        $foreignQuote = $quote->replicate();
        $foreignQuote->organization_id = $otherOrganization->id;
        $foreignQuote->measurement_unit_id = $foreignUnit->id;
        $foreignQuote->save();
        $foreignSource = app(\App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader::class)
            ->readMaterialPrices((int) $otherOrganization->id, ['catalog:'.$foreignQuote->id])['catalog:'.$foreignQuote->id];
        $before = $session->fresh()->getRawOriginal();
        $this->postJson($url.'/resource-price', [...$input, 'source_reference' => 'catalog:'.$foreignQuote->id,
            'source_hash' => $foreignSource['source_hash']])->assertUnprocessable();
        $sources = $this->getJson($url.'/resource-price-sources?work_item_key=rough-floor&norm_resource_id='.$input['norm_resource_id'])
            ->assertOk()->json('data.sources');
        self::assertNotContains('catalog:'.$foreignQuote->id, array_column($sources, 'source_reference'));
        $actor->update(['current_organization_id' => $otherOrganization->id]);
        $this->withToken(auth('api_admin')->login($actor->fresh()));
        $this->getJson($url.'/resources?work_item_key=rough-floor')->assertNotFound();
        $this->postJson($url.'/resource-price', $input)->assertNotFound();
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function choice(string $url, EstimateGenerationSession $session, EstimatePositionCatalog $quote): array
    {
        $target = $this->getJson($url.'/resources?work_item_key=rough-floor')->assertOk()->json('data.resources.0');
        $sources = $this->getJson($url.'/resource-price-sources?work_item_key=rough-floor&norm_resource_id='.$target['norm_resource_id'])->assertOk()->json('data');
        $source = array_values(array_filter($sources['sources'], static fn (array $price): bool => $price['source_reference'] === 'catalog:'.$quote->id))[0];
        self::assertTrue($source['unit_compatible']);
        self::assertTrue($source['source_conditions_available']);

        return ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->fresh()->state_version,
            'work_item_key' => 'rough-floor', 'norm_resource_id' => $target['norm_resource_id'], 'target_hash' => $target['target_hash'],
            'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash'], 'same_product_confirmed' => true,
            'reason' => 'Для выбранного нормативного раствора подтверждены марка М100 и соответствие позиции каталога; расход остаётся по нормативу.'];
    }

    private function recalculate(User $actor, EstimateGenerationSession $session): array
    {
        return app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            (int) $session->fresh()->state_version, (string) Str::uuid());
    }

    private function rows(array $draft): array
    {
        return array_column($draft['local_estimates'][0]['sections'][0]['work_items'], null, 'key');
    }

    private function fixture(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true, 'jwt.secret' => str_repeat('resource-test', 6), 'jwt.blacklist_enabled' => false]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturnTrue();
        $permissions->allows('canAccessInterface')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $permissions);
        $units = [];
        foreach ([['Квадратный метр', 'м²', 'area'], ['Кубический метр', 'м³', 'volume'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            $units[$type] = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $this->normativeCatalog();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'mixed',
                'region' => 'Тестовый регион', 'regional_context' => $catalog['context'], 'composition_generation_id' => 'resource-fixture:first',
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500]]);
        app(SaveSessionPricingSource::class)->handle($actor, (int) $project->id, (int) $session->id, ['request_id' => (string) Str::uuid(),
            'state_version' => 0, 'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
            'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery' => 'outside_scope',
                'basis' => 'Проверка выбранных работ пола; доставка явно за пределами выбранного состава.']]);
        $manual = app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 1, (string) Str::uuid(), ['entities' => [[
            'key' => 'independent-room', 'type' => 'room', 'parameters' => ['length' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement']]],
        ]]);
        $quantity = $manual['quantities']['floor_area'];
        $items = [];
        foreach ([['rough-floor', 'rough.floor', 'Черновая подготовка пола', 'Устройство цементной стяжки пола толщиной 20 мм', '11-01-011-01', $catalog['rough']],
            ['finish-floor', 'finish.floor', 'Чистовое покрытие пола', 'Устройство покрытий из досок ламинированных замковым способом', '11-01-034-04', $catalog['finish']]] as [$key, $work, $name, $search, $code, $normId]) {
            $items[] = ['key' => $key, 'work_key' => $work, 'name' => $name, 'unit' => 'm2', 'quantity' => '6', 'quantity_status' => 'supported',
                'quantity_evidence' => $quantity, 'quantity_basis_details' => $quantity, 'item_type' => 'priced_work', 'source_refs' => [],
                'labor_hours' => '0', 'machinery_hours' => '0', 'normative_search_text' => $search, 'normative_rate_code' => $code,
                'normative_match' => ['norm_id' => $normId], 'metadata' => ['composition_work_key' => $work, 'quantity_key' => $work,
                    'scope_entity_id' => $quantity['formula_inputs']['entity_id']]];
            if ($work === 'finish.floor') {
                $items[array_key_last($items)]['specialization_scenario'] = (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog)->issue('finish.floor', 'residential');
            }
        }
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT, 'regional_context' => $catalog['context'],
            'evaluation_policy' => ['selected_sections' => ['finishing']], 'object_profile' => ['object_type' => 'residential'],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'local_estimates' => [['key' => 'finish_finishing', 'title' => 'Отделка', 'source_refs' => [], 'sections' => [[
                'key' => 'floors', 'title' => 'Полы', 'source_refs' => [], 'work_items' => $items]]]]];
        $session->refresh()->update(['status' => 'estimate_review_required', 'draft_payload' => app(BuildMostEstimateDraft::class)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        foreach ($items as $item) {
            app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $organization->id, (int) $project->id,
                $actor, (int) $session->fresh()->state_version, $item['key'], $item['normative_match']['norm_id'], false);
        }
        $this->recalculate($actor, $session);
        $quote = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'created_by_user_id' => $actor->id,
            'name' => 'Раствор цементно-песчаный М100', 'code' => 'M100-'.Str::random(8), 'item_type' => 'material',
            'measurement_unit_id' => $units['volume']->id, 'unit_price' => '119', 'is_active' => true,
            'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Тестовый регион',
                'vat_mode' => 'included', 'vat_rate' => '19', 'delivery_included' => true, 'product_specification' => ['grade' => 'M100']]]);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_resource-prices/projects/{project}/sessions/{session}/evaluation';
        foreach (['resources' => 'resources', 'resource-price-sources' => 'sources'] as $path => $method) {
            Route::get($route.'/'.$path, [EstimateGenerationResourcePriceController::class, $method])->middleware(['auth:api_admin', SubstituteBindings::class, 'interface:admin']);
        }
        Route::post($route.'/resource-price', [EstimateGenerationResourcePriceController::class, 'save'])->middleware(['auth:api_admin', SubstituteBindings::class, 'interface:admin']);
        $this->withToken(auth('api_admin')->login($actor));

        return [$actor, $session, $quote, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route)];
    }

    private function normativeCatalog(): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Тестовый нормативный состав', 'norm_type' => 'gesn', 'source_file' => 'fixture', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '11', 'name' => 'Полы', 'path' => '11/floors', 'created_at' => $now, 'updated_at' => $now]);
        $ids = [];
        foreach ([['rough', '11-01-011-01', 'Устройство цементной стяжки пола толщиной 20 мм'], ['finish', '11-01-034-04', 'Устройство покрытий из досок ламинированных замковым способом']] as [$type, $code, $name]) {
            $ids[$type] = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => $code, 'section_code' => '11', 'section_name' => 'Полы', 'section_id' => $section, 'name' => $name, 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $ids[$type], 'resource_code' => $key.'-labor', 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_norm_resources')->insert([
            ['estimate_norm_id' => $ids['rough'], 'resource_code' => '04.3.01.09', 'resource_name' => 'Раствор готовый кладочный цементно-песчаный', 'unit' => 'м3', 'quantity' => '0.020000', 'resource_type' => 'material', 'raw_payload' => json_encode(['source_tag' => 'AbstractResource']), 'created_at' => $now, 'updated_at' => $now],
            ['estimate_norm_id' => $ids['finish'], 'resource_code' => $key.'-laminate', 'resource_name' => 'Ламинат', 'unit' => 'м2', 'quantity' => '1.050000', 'resource_type' => 'material', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Тестовый регион', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Тестовая зона', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$key.'-labor', 'Труд рабочих', 'h', '30', 'labor'], ['04.3.01.09-0054', 'Раствор готовый кладочный цементно-песчаный М100', 'м3', '90', 'material'], [$key.'-laminate', 'Ламинат', 'м2', '200', 'material']] as [$code, $name, $unit, $price, $type]) {
            DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $code, 'resource_name' => $name, 'unit' => $unit, 'base_price' => $price, 'price_type' => $type, 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return [...$ids, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key, 'version_key' => $key, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4]];
    }

    private function assertExport(User $actor, EstimateGenerationSession $session, int $quoteId, string $factId): void
    {
        $before = $session->fresh()->getRawOriginal();
        $export = app(ReadConfirmedEvaluationExport::class)->read($actor, (int) $session->project_id, (int) $session->id);
        $file = app(EstimateGenerationExcelExportService::class)->export($export);
        self::assertSame($before, $session->fresh()->getRawOriginal());
        $path = tempnam(sys_get_temp_dir(), 'most-resource-quote-');
        $book = null;
        try {
            file_put_contents($path, $file['content']);
            $book = IOFactory::load($path);
            self::assertNotNull($book->getSheetByName('Смета'));
            $metadataSheet = $book->getSheetByName('_METADATA_');
            self::assertNotNull($metadataSheet);
            $metadata = json_decode((string) $metadataSheet->getCell('A1')->getValue(), true, 64, JSON_THROW_ON_ERROR);
            if (($metadata['encoding'] ?? null) === 'utf8-json-chunks') {
                $json = '';
                for ($row = 2; $row <= $metadata['chunk_count'] + 1; $row++) {
                    $json .= (string) $metadataSheet->getCell('A'.$row)->getValue();
                }
                self::assertSame($metadata['sha256'], hash('sha256', $json));
                $metadata = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            }
            self::assertCount(6, $metadata['items']);
            $resources = array_values(array_filter($metadata['items'], static fn (array $item): bool => $item['parent_work_id'] !== null));
            self::assertCount(4, $resources);
            $quoted = array_values(array_filter($resources, static fn (array $item): bool => isset($item['metadata']['normative_ref']['catalog_price_selection'])));
            self::assertCount(1, $quoted);
            self::assertSame(100.0, (float) $quoted[0]['unit_price']);
            self::assertSame(0.12, (float) $quoted[0]['quantity_total']);
            $proof = $quoted[0]['metadata']['normative_ref']['catalog_price_selection'];
            self::assertSame($factId, $proof['fact_id']);
            self::assertSame('catalog:'.$quoteId, $proof['source_snapshot']['source_reference']);
            self::assertSame('19', $proof['source_snapshot']['vat_rate']);
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }
}
