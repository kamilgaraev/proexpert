<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\FinalizedPackageDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class OrdinaryEstimateWriterRoundTripPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('roundTripMoneyCases')]
    public function test_real_manual_facts_normative_finalization_writer_recalculation_and_export_agree_to_the_kopeck(array $case): void
    {
        $lighting = ($case['lighting'] ?? false) === true;
        $scenario = $lighting ? app(ResidentialMaterialScenarioCatalog::class)->issue('lighting.fixtures', 'residential') : null;
        Queue::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => $lighting ? 'estimate_review_required' : 'draft', 'state_version' => 0, 'input_payload' => [],
            'draft_payload' => $lighting ? ['local_estimates' => [['sections' => [['work_items' => [
                ['key' => 'lighting.fixtures', 'name' => 'Установка потолочных светильников', 'quantity' => null, 'unit' => 'pcs'],
            ]]]]]] : null]);
        if ($lighting) {
            $receipt = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)
                ->handle($actor, (int) $project->id, (int) $session->id, ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                    'work_item_key' => 'lighting.fixtures', 'value' => '1', 'unit' => 'pcs', 'reason' => 'Пользователь подтвердил установку одного светильника.']);
            self::assertSame('0', $receipt['cost_units']);
        } else {
            app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(),
                ['entities' => array_map(static fn (int $number): array => ['key' => 'room-'.$number, 'type' => 'room', 'parameters' => [
                    'length' => ['value' => $case['length'], 'unit' => 'm', 'basis' => 'measurement'],
                    'width' => ['value' => $case['width'], 'unit' => 'm', 'basis' => 'measurement'],
                ]], range(1, $case['rooms']))]);
        }
        $session->refresh();
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $available = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $quantities = $lighting ? [$available[$receipt['quantity_key']]] : array_values(array_filter($available,
            static fn ($quantity): bool => $quantity->formulaKey === 'floor_area'));
        self::assertCount($case['rooms'], $quantities);
        $quantity = $quantities[0];
        $sourceVersion = $quantity->formulaInputs['operands'][0]['source_version'];
        $catalog = $this->catalog($case['resources'], $case['price'], $lighting);
        $technologyHash = hash('sha256', 'test:no_optional_technology');
        $ruleHash = hash('sha256', 'test:selected_floor_work');
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$scope, $sourceVersion, $capture['token'], 'test:technology:v1', $technologyHash, [], []]));
        self::assertTrue($models->replaceCompleteness(...[...$scope, $sourceVersion, $capture['token'], 'test:technology:v1', $technologyHash, 'test:rules:v1', $ruleHash, [], []]));
        $technology = $models->currentTechnologyRecommendations(...$scope);
        $completeness = $models->currentCompleteness(...$scope);
        $base = app(SessionBaseInputVersionResolver::class)->resolve($session);
        $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $base, $session->status->value, baseInputVersion: $base);
        $items = [];
        $supplementaryMaterials = [];
        foreach ($quantities as $quantity) {
            $item = ['key' => $lighting ? 'lighting.fixtures' : 'work:floor:'.$quantity->formulaInputs['entity_id'],
                'name' => $lighting ? 'Установка потолочных светильников' : 'Устройство пола', 'description' => 'Состав работы и количество подтверждены пользователем',
                'item_type' => 'priced_work', 'quantity' => $quantity->amount, 'unit' => $quantity->unit,
                'normative_rate_code' => $lighting ? $scenario['normative_rate_code'] : '11-01-001-01', 'quantity_basis' => 'Количество введено и подтверждено пользователем',
                'quantity_evidence' => $quantity->toArray(), 'quantity_basis_details' => $quantity->toArray(),
                'metadata' => ['quantity_key' => $quantity->key, 'scope_entity_id' => $quantity->formulaInputs['entity_id']], 'source_refs' => [], 'confidence' => 1.0];
            if ($lighting) {
                $item['specialization_scenario'] = $scenario;
                $item['normative_search_text'] = $scenario['normative_search_text'];
            }
            $node = app(AcceptedQuantityEvidenceMaterializer::class)->materialize($context, $quantity, $item);
            $item = [...$item, 'quantity_evidence_id' => $node->id, 'quantity_evidence_fingerprint' => $node->fingerprint,
                'quantity_evidence_source_version' => $node->sourceVersion];
            $match = app(EstimateNormativeMatcher::class)->matchSelectedNorm($catalog['norm_id'], $item, $catalog['context']);
            self::assertNotNull($match);
            $assembly = app(ResourceAssemblyService::class);
            self::assertInstanceOf(\App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionHardGate::class,
                (new \ReflectionProperty(ResourceAssemblyService::class, 'selectionHardGate'))->getValue($assembly));
            self::assertSame([], app(\App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionHardGate::class)->rejectionReasons($item, $catalog['context'], $match),
                json_encode(['section' => $match['selected']['section'], 'intent' => app(\App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier::class)->classify($item, $catalog['context'])]));
            $item['normative_retrieval'] = ['status' => 'retrieval_only', 'blocking_issues' => []];
            $item = app(ResourceAssemblyService::class)->applySelectedNormativeMatch($item, $match, $catalog['context']);
            self::assertCount($case['resources'], $item['labor'] ?? [], json_encode(['decision' => array_intersect_key($item['normative_match']['decision'] ?? [], array_flip(['status', 'reasons', 'warnings', 'confidence'])),
                'intent' => $item['work_intent'] ?? null, 'flags' => $item['validation_flags'] ?? []]));
            if ($lighting) {
                $materials = app(ResidentialProjectMaterialCatalog::class);
                $requirement = $materials->requirementForIntent(['specialization_scenario' => $scenario]);
                self::assertNotNull($requirement);
                $resource = $materials->resourceFromPriceRows($requirement, [$catalog['material_row']]);
                self::assertNotNull($resource);
                $assembled = app(AssembleMatchedResources::class)->handle(['local_estimates' => [['sections' => [['work_items' => [$item]]]]],
                    'supplementary_materials' => [['work_item_key' => 'lighting.fixtures', 'status' => 'priced', 'resource' => $resource]]]);
                $item = $assembled['data']['local_estimates'][0]['sections'][0]['work_items'][0];
                $supplementaryMaterials = $assembled['data']['supplementary_materials'];
                self::assertCount(1, $item['materials']);
            }
            self::assertNull(app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier::class)->rejectionReason(...[...$scope, $base, $item]));
            $item = app(EstimatePricingService::class)->price([$item], $catalog['context'], $context)[0];
            self::assertSame('calculated', $item['pricing_status'], json_encode($item));
            self::assertCount($case['resources'], $item['labor']);
            self::assertSame($case['work_direct'], $item['total_cost']);
            foreach ($item['labor'] as $resource) {
                self::assertSame($case['resource_total'], $resource['total_price']);
            }
            $items[] = $item;
        }
        $draft = ['title' => $lighting ? 'Смета освещения' : 'Смета выбранного пола', 'source_input_version' => $base, 'input_snapshot_hash' => $capture['token'],
            'supplementary_materials' => $supplementaryMaterials,
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'source_version' => $sourceVersion],
            'catalog_identity' => ['version' => $catalog['context']['normative_dataset_version'], 'hash' => hash('sha256', $catalog['context']['normative_dataset_version']), 'status' => 'current'],
            'technology_identity' => ['version' => $technology['catalog_version'], 'hash' => $technology['catalog_hash'], 'run_id' => $technology['run_id'], 'status' => 'current'],
            'rule_identity' => ['version' => $completeness['rule_catalog_version'], 'hash' => $completeness['rule_catalog_hash'], 'run_id' => $completeness['run_id'], 'status' => 'current'],
            'regional_context' => $catalog['context'], 'vat_rate' => '20', 'contingency' => ['kind' => 'fixed', 'amount' => $case['contingency']], 'contingency_amount' => $case['contingency'],
            'local_estimates' => [['key' => 'local:floor', 'title' => 'Работы на измеренной площади', 'status' => 'ready_for_review', 'source_refs' => [], 'confidence' => 1.0,
                'sections' => [['key' => 'floor', 'title' => 'Пол', 'source_refs' => [], 'work_items' => $items]]]]];
        app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $draft, $base);
        $persisted = $session->packages()->firstOrFail()->items()->orderBy('id')->firstOrFail();
        self::assertSame($lighting ? 'supplementary_project_material:v5' : 'semantic_project_resource:v9', $persisted->price_snapshot['coefficients']['pricing_formula_version']);
        self::assertSame('resource_half_up_2', $persisted->price_snapshot['coefficients']['rounding_policy']);
        self::assertSame($case['work_direct'], $persisted->total_cost);
        self::assertSame(array_fill(0, $case['resources'], $case['resource_total']),
            array_column($persisted->price_snapshot['coefficients']['resource_evidence'], 'final_amount'));
        $historical = json_decode((string) DB::scalar($lighting ? 'SELECT public.eg_expected_package_item_price_closed_v4(?)' : 'SELECT public.eg_expected_package_item_price_closed_v8(?)', [$persisted->id]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($case['historical_work'], (string) \Brick\Math\BigDecimal::of((string) $historical['money'])->toScale(2));
        self::assertSame($lighting ? 'supplementary_project_material:v4' : 'semantic_project_resource:v8', $historical['snapshot']['coefficients']['pricing_formula_version']);
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_16_000100_round_normative_resource_amounts_before_summing.php');
        try {
            $migration->down();
            self::fail('An in-use pricing formula cannot be rolled back.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation.resource_rounding_rollback_blocked', $exception->getMessage());
        }
        $draft = app(FinalizedPackageDraftProjector::class)->project($session, $draft);
        $before = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $builder = app(BuildMostEstimateDraft::class);
        $draft = $builder->build($draft);
        self::assertSame([], $draft['stage6_review_items'], json_encode(['norm' => array_intersect_key($before['normative_match'], array_flip(['status', 'hard_gate_passed', 'dataset_version'])),
            'retrieval' => $before['normative_retrieval'] ?? null, 'pricing_status' => $before['pricing_status'], 'blocker' => $before['pricing_blocker'] ?? null,
            'finalized' => $before['pricing_finalized_at'], 'captured' => $before['price_snapshot']['captured_at'], 'context' => $catalog['context']]));
        $draft = $builder->seal(app(EstimateValidationService::class)->validate($draft));
        self::assertNull(app(EstimateDraftPersistenceService::class)->findApplyBlocker($draft), json_encode($draft['quality_summary']));
        $session->update(['draft_payload' => $draft]);
        $id = app(LaravelGeneratedEstimateWriter::class)->createFromSession($session,
            new ApplyGeneratedEstimateCommand($scope[2], $scope[0], $scope[1], (int) $session->state_version));
        $estimate = Estimate::query()->findOrFail($id);
        self::assertSame($case['total'], $estimate->total_amount);
        self::assertSame($case['with_vat'], $estimate->total_amount_with_vat);
        self::assertSame($case['direct'], $estimate->total_direct_costs);
        $works = EstimateItem::query()->where('estimate_id', $id)->whereNull('parent_work_id')->orderBy('id')->get();
        self::assertCount($case['rooms'], $works);
        foreach ($works as $parent) {
            self::assertSame((string) \Brick\Math\BigDecimal::of($case['labor_direct'] ?? $case['work_direct'])->toScale(4), $parent->labor_cost);
            self::assertSame($case['resources'] + ($lighting ? 1 : 0), EstimateItem::query()->where('parent_work_id', $parent->id)->count());
        }
        $work = $works[0];
        self::assertSame((string) \Brick\Math\BigDecimal::of($case['labor_direct'] ?? $case['work_direct'])->toScale(4), $work->labor_cost);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame($case['total'], $estimate->total_amount);
            self::assertSame($case['with_vat'], $estimate->total_amount_with_vat);
            foreach ($works as $parent) {
                self::assertSame((string) \Brick\Math\BigDecimal::of($case['labor_direct'] ?? $case['work_direct'])->toScale(4), $parent->fresh()->labor_cost);
            }
            $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate);
            self::assertSame($case['with_vat'], (string) \Brick\Math\BigDecimal::of((string) $export['total_amount_with_vat'])->toScale(2));
            self::assertSame($case['contingency'], $export['contingency_amount']);
        }
        $session->refresh()->update(['status' => 'estimate_review_required']);
        $beforeUsage = DB::table('estimate_generation_ai_usage')->count();
        $quantityReceipt = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)
            ->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->state_version,
                'work_item_key' => $items[0]['key'], 'value' => '25', 'unit' => $lighting ? 'pcs' : 'm2', 'reason' => 'Пользователь подтвердил новое количество выбранной работы.']);
        $requestId = (string) Str::uuid();
        $recalculator = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation::class);
        $result = $recalculator->handle($actor, $scope[1], $scope[2], $quantityReceipt['state_version'], $requestId);
        self::assertSame('0', $result['cost_units']);
        self::assertEquals($result, $recalculator->handle($actor, $scope[1], $scope[2], $quantityReceipt['state_version'], $requestId));
        $session->refresh();
        $updated = $session->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertTrue(\Brick\Math\BigDecimal::of($updated['quantity'])->isEqualTo('25'));
        if ($lighting) {
            self::assertCount(1, $updated['materials']);
            self::assertTrue(\Brick\Math\BigDecimal::of($updated['materials'][0]['quantity'])->isEqualTo('25'));
            self::assertSame('0.13', $updated['materials'][0]['total_price']);
            self::assertSame((int) $catalog['material_row']->price_id, (int) $updated['materials'][0]['normative_ref']['price_id']);
            $price = $result['result']['scenarios'][0]['positions'][0]['price_snapshot'];
            self::assertNotNull($price);
            self::assertSame('normative', $price['source_type']);
            self::assertNull($price['level']);
            self::assertNull($price['price_period_id']);
            self::assertNull($price['price_version_id']);
            self::assertCount(3, $price['resource_sources']);
            self::assertContains('regional_catalog', array_column($price['resource_sources'], 'source_type'));
            self::assertContains('estimate_resource_prices:'.$catalog['material_row']->price_id, array_column($price['resource_sources'], 'source_reference'));
            self::assertContains('base_catalog', array_column(array_column($price['resource_sources'], 'coefficients'), 'price_kind'));
            self::assertSame('0.39', (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationLineAmount)
                ->calculate(\Brick\Math\BigDecimal::of('25'), \Brick\Math\BigDecimal::of($price['unit_price']), $price));
        }
        self::assertSame($case['revised_work'], $updated['total_cost']);
        self::assertSame($case['revised_total'], $session->draft_payload['totals']['total_cost']);
        self::assertSame($beforeUsage, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame($case['total'], $estimate->fresh()->total_amount, 'A free source revision must not rewrite the existing ordinary estimate.');
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class)
            ->assertCurrent(...[...$scope, $session->draft_payload]));
        self::addToAssertionCount(1);
        if ($lighting) {
            $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
            $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
            $version = app(SessionBaseInputVersionResolver::class)->resolve($session);
            $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $version, $session->status->value, baseInputVersion: $version);
            foreach ([[], [['work_item_key' => 'lighting.fixtures', 'status' => 'missing', 'resource' => $supplementaryMaterials[0]['resource']]]] as $pins) {
                $incomplete = [...$session->draft_payload, 'supplementary_materials' => $pins];
                $incomplete = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator::class)
                    ->recalculate($incomplete, $context, $capture['snapshot'], $decisions, $actor, $session);
                $work = $incomplete['local_estimates'][0]['sections'][0]['work_items'][0];
                self::assertSame('project_material_price_missing', $work['pricing_blocker']);
                $evaluation = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder::class)
                    ->build($incomplete, $capture['snapshot'], $decisions);
                self::assertNull($evaluation['scenarios'][0]['positions'][0]['price_snapshot']);
            }
        }
    }

    public static function roundTripMoneyCases(): array
    {
        return [
            'ordinary_with_contingency_and_vat' => [[
                'rooms' => 1, 'length' => '5', 'width' => '4', 'resources' => 1, 'price' => '30.0000',
                'resource_total' => '600.00', 'work_direct' => '600.00', 'historical_work' => '600.00', 'direct' => '600.00', 'contingency' => '15.55',
                'total' => '615.55', 'with_vat' => '738.66', 'revised_work' => '750', 'revised_total' => '765.55',
            ]],
            'two_half_kopeck_resources' => [[
                'rooms' => 1, 'length' => '1', 'width' => '1', 'resources' => 2, 'price' => '0.0050',
                'resource_total' => '0.01', 'work_direct' => '0.02', 'historical_work' => '0.01', 'direct' => '0.02', 'contingency' => '0.00',
                'total' => '0.02', 'with_vat' => '0.02', 'revised_work' => '0.26', 'revised_total' => '0.26',
            ]],
            'many_physical_work_rows_with_half_kopeck_resources' => [[
                'rooms' => 40, 'length' => '1', 'width' => '1', 'resources' => 5, 'price' => '0.0050',
                'resource_total' => '0.01', 'work_direct' => '0.05', 'historical_work' => '0.03', 'direct' => '2.00', 'contingency' => '0.00',
                'total' => '2.00', 'with_vat' => '2.40', 'revised_work' => '0.65', 'revised_total' => '2.60',
            ]],
            'lighting_with_supplementary_half_kopeck_resource' => [[
                'lighting' => true, 'rooms' => 1, 'resources' => 2, 'price' => '0.0050', 'resource_total' => '0.01',
                'work_direct' => '0.03', 'labor_direct' => '0.02', 'historical_work' => '0.02', 'direct' => '0.03', 'contingency' => '0.00',
                'total' => '0.03', 'with_vat' => '0.04', 'revised_work' => '0.39', 'revised_total' => '0.39',
            ]],
        ];
    }

    private function catalog(int $resourceCount, string $resourcePrice, bool $lighting): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $scenario = $lighting ? app(ResidentialMaterialScenarioCatalog::class)->issue('lighting.fixtures', 'residential') : null;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Проверенная тестовая норма', 'norm_type' => $lighting ? 'gesnm' : 'gesn', 'source_file' => 'recorded-test', 'created_at' => $now, 'updated_at' => $now]);
        $sectionCode = $lighting ? '08-03' : '11';
        $sectionName = $lighting ? 'Освещение' : 'Полы';
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => $sectionCode, 'name' => $sectionName, 'path' => $sectionCode, 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => $lighting ? $scenario['normative_rate_code'] : '11-01-001-01', 'section_code' => $sectionCode, 'section_name' => $sectionName,
            'section_id' => $section, 'name' => $lighting ? $scenario['normative_search_text'] : 'Устройство пола', 'unit' => $lighting ? 'шт' : 'м2', 'created_at' => $now, 'updated_at' => $now]);
        for ($resourceIndex = 0; $resourceIndex < $resourceCount; $resourceIndex++) {
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => $key.'-'.$resourceIndex, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        }
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Тестовый регион', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Тестовая зона', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2099)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        for ($resourceIndex = 0; $resourceIndex < $resourceCount; $resourceIndex++) {
            DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key.'-'.$resourceIndex, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => $resourcePrice, 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);
        $materialRow = null;
        if ($lighting) {
            $materialId = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $dataset,
                'resource_code' => '59.1.20.03-0798', 'resource_name' => 'Светильник светодиодный потолочный 18 Вт',
                'unit' => 'шт', 'base_price' => '0.0050', 'price_type' => 'material', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            $materialRow = DB::table('estimate_resource_prices as rp')->join('estimate_dataset_versions as dv', 'dv.id', '=', 'rp.dataset_version_id')
                ->where('rp.id', $materialId)->where('dv.source_type', 'fsnb_2022')
                ->select('rp.id as price_id', 'rp.resource_code', 'rp.resource_name', 'rp.unit', 'rp.base_price', 'dv.version_key as price_source_version')
                ->selectRaw("NULL as construction_resource_id, 'fsnb_base' as price_source")->first();
        }

        return ['norm_id' => $norm, 'material_row' => $materialRow, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key,
            ...$lighting ? ['scope_type' => 'engineering', 'section_title' => 'Освещение', 'object_type' => 'residential'] : []]];
    }
}
