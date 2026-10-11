<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\FinalizedPackageDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
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
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class OrdinaryEstimateWriterRoundTripPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_manual_facts_normative_finalization_writer_recalculation_and_export_agree_to_the_kopeck(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(),
            ['entities' => [['key' => 'room-1', 'type' => 'room', 'parameters' => [
                'length' => ['value' => '5', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '4', 'unit' => 'm', 'basis' => 'measurement'],
            ]]]]);
        $session->refresh();
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities']['floor_area'];
        $sourceVersion = $quantity->formulaInputs['operands'][0]['source_version'];
        $catalog = $this->catalog();
        $technologyHash = hash('sha256', 'test:no_optional_technology');
        $ruleHash = hash('sha256', 'test:selected_floor_work');
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$scope, $sourceVersion, $capture['token'], 'test:technology:v1', $technologyHash, [], []]));
        self::assertTrue($models->replaceCompleteness(...[...$scope, $sourceVersion, $capture['token'], 'test:technology:v1', $technologyHash, 'test:rules:v1', $ruleHash, [], []]));
        $technology = $models->currentTechnologyRecommendations(...$scope);
        $completeness = $models->currentCompleteness(...$scope);
        $base = app(SessionBaseInputVersionResolver::class)->resolve($session);
        $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $base, $session->status->value, baseInputVersion: $base);
        $item = ['key' => 'work:floor', 'name' => 'Устройство пола', 'description' => 'Выбранный состав: труд на измеренной площади',
            'item_type' => 'priced_work', 'quantity' => '20', 'unit' => 'm2', 'normative_rate_code' => '11-01-001-01', 'quantity_basis' => 'Размеры введены и подтверждены пользователем',
            'quantity_evidence' => $quantity->toArray(), 'quantity_basis_details' => $quantity->toArray(),
            'metadata' => ['quantity_key' => 'floor_area'], 'source_refs' => [], 'confidence' => 1.0];
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
        self::assertNull(app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier::class)->rejectionReason(...[...$scope, $base, $item]));
        $item = app(EstimatePricingService::class)->price([$item], $catalog['context'], $context)[0];
        self::assertSame('calculated', $item['pricing_status'], json_encode($item));
        $draft = ['title' => 'Смета выбранного пола', 'source_input_version' => $base, 'input_snapshot_hash' => $capture['token'],
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'source_version' => $sourceVersion],
            'catalog_identity' => ['version' => $catalog['context']['normative_dataset_version'], 'hash' => hash('sha256', $catalog['context']['normative_dataset_version']), 'status' => 'current'],
            'technology_identity' => ['version' => $technology['catalog_version'], 'hash' => $technology['catalog_hash'], 'run_id' => $technology['run_id'], 'status' => 'current'],
            'rule_identity' => ['version' => $completeness['rule_catalog_version'], 'hash' => $completeness['rule_catalog_hash'], 'run_id' => $completeness['run_id'], 'status' => 'current'],
            'regional_context' => $catalog['context'], 'vat_rate' => '20', 'contingency' => ['kind' => 'fixed', 'amount' => '15.55'], 'contingency_amount' => '15.55',
            'local_estimates' => [['key' => 'local:floor', 'title' => 'Работы на измеренной площади', 'status' => 'ready_for_review', 'source_refs' => [], 'confidence' => 1.0,
                'sections' => [['key' => 'floor', 'title' => 'Пол', 'source_refs' => [], 'work_items' => [$item]]]]]];
        app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $draft, $base);
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
        self::assertSame('615.55', $estimate->total_amount);
        self::assertSame('738.66', $estimate->total_amount_with_vat);
        self::assertSame('600.00', $estimate->total_direct_costs);
        $work = EstimateItem::query()->where('estimate_id', $id)->whereNull('parent_work_id')->sole();
        self::assertSame('600.0000', $work->labor_cost);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame('615.55', $estimate->total_amount);
            self::assertSame('738.66', $estimate->total_amount_with_vat);
            self::assertSame('600.0000', $work->fresh()->labor_cost);
            $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate);
            self::assertSame('738.66', (string) $export['total_amount_with_vat']);
            self::assertSame('15.55', $export['contingency_amount']);
        }
    }

    private function catalog(): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Проверенная тестовая норма', 'norm_type' => 'gesn', 'source_file' => 'recorded-test', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '11', 'name' => 'Полы', 'path' => '11/floors', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '11-01-001-01', 'section_code' => '11', 'section_name' => 'Полы',
            'section_id' => $section, 'name' => 'Устройство пола', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Тестовый регион', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Тестовая зона', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
