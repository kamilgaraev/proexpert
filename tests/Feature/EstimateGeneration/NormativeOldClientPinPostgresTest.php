<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class NormativeOldClientPinPostgresTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_old_client_post_persists_server_pin_and_rejects_missing_unapproved_or_mismatch(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $disposable = str_ends_with($database, '_contract') || getenv('ALLOW_DESTRUCTIVE_CONTRACT_DB') === '1';
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! $disposable) {
            self::markTestSkipped('Requires opt-in migrated PostgreSQL contract database.');
        }

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_active' => true,
            'project_access_mode' => 'all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        DB::table('project_user')->insert([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'is_active' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $version = 'old-client-'.strtolower((string) str()->ulid());
        $dataset = EstimateDatasetVersion::query()->create([
            'source_type' => EstimateSourceType::FSNB_2022,
            'version_key' => $version, 'bucket' => 'contract', 'prefix' => $version,
            'status' => EstimateImportStatus::PARSED,
            'rows_imported' => 1,
            'errors_count' => 0,
            'finished_at' => now(),
        ]);
        $moduleId = (int) DB::table('modules')->insertGetId([
            'name' => 'AI-сметчик',
            'slug' => 'estimate-generation',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'category' => 'estimates',
            'permissions' => json_encode(['estimate_generation.create'], JSON_THROW_ON_ERROR),
            'display_order' => 1,
            'is_active' => true,
            'is_system_module' => true,
            'can_deactivate' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            $user,
            'organization_admin',
            AuthorizationContext::getOrganizationContext((int) $organization->id),
        );
        $this->app->instance(NormativePinClock::class, new class implements NormativePinClock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-12T10:00:00+03:00');
            }
        });
        $url = "/api/v1/admin/projects/{$project->id}/estimate-generation/sessions";
        $fixtureDatasetIds = [];
        $unauthorized = null;

        try {
            $unauthorized = User::factory()->create(['current_organization_id' => $organization->id]);
            DB::table('organization_user')->insert([
                'organization_id' => $organization->id,
                'user_id' => $unauthorized->id,
                'is_owner' => false,
                'is_active' => true,
                'project_access_mode' => 'assigned_only',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('project_user')->insert([
                'project_id' => $project->id,
                'user_id' => $unauthorized->id,
                'role' => 'viewer',
                'is_active' => true,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->actingAs($unauthorized, 'api_admin');
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($unauthorized));
            $this->postJson($url, ['description' => 'Кладка кирпичных стен', 'area' => 100])->assertForbidden();
            self::assertSame(0, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());

            $this->actingAs($user, 'api_admin');
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($user));
            $response = $this->postJson($url, ['description' => 'Кладка кирпичных стен', 'area' => 100]);
            $response->assertCreated();
            $session = EstimateGenerationSession::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
            self::assertSame($version, $session->input_payload['regional_context']['normative_dataset_version']);
            self::assertSame('2026-07-12', $session->input_payload['regional_context']['business_date']);
            $artifacts = new InMemoryPipelineArtifactStore;
            $graph = PipelineDefinitionGraph::standard();
            $this->app->instance(StageResultFactory::class, new StageResultFactory($artifacts, $graph));
            $this->app->forgetInstance(PlanWorkItemsStage::class);
            $analysis = $this->analysis($session->input_payload['regional_context']);
            $planContext = $this->planContext($session, $analysis, $graph);
            $planResult = $this->app->make(PlanWorkItemsStage::class)->execute($planContext);
            $plan = $planResult->transientData;
            self::assertIsArray($plan);
            self::assertNotEmpty($plan['local_estimates']);
            $pin = $plan['normative_context_pin'];
            self::assertSame('pinned', $pin['status']);
            self::assertSame($session->input_payload['regional_context']['normative_dataset_version'], $pin['dataset_version']);
            self::assertSame($session->input_payload['regional_context']['business_date'], $pin['applicability_date']);

            $changedAnalysis = $analysis;
            $changedAnalysis['regional_context']['business_date'] = '2026-07-13';
            $changedPlanContext = $this->planContext($session, $changedAnalysis, $graph);
            $changedPlan = $this->app->make(PlanWorkItemsStage::class)->execute($changedPlanContext);
            self::assertNotSame($planContext->inputVersion, $changedPlanContext->inputVersion);
            self::assertNotSame($planResult->outputVersion, $changedPlan->outputVersion);

            $plannedWall = collect($this->workItems($plan['local_estimates']))->firstWhere('normative_rate_code', '08-01-001-01');
            self::assertIsArray($plannedWall);
            $priceDatasetId = $this->dataset('fsbc', 'prices-'.$version);
            $fixtureDatasetIds[] = $priceDatasetId;
            $normId = $this->seedCompatibleNorm((int) $dataset->id, $priceDatasetId, $version, $plannedWall);
            $competingDatasetId = $this->dataset('fsnb_2022', 'latest-'.$version);
            $fixtureDatasetIds[] = $competingDatasetId;
            $competingNormId = $this->seedNorm($competingDatasetId, 'latest-'.$version, 'Кладка наружных кирпичных стен');
            DB::table('estimate_normative_retrieval_rollouts')->updateOrInsert(
                ['schema_version' => 'normative-retrieval-v1'],
                ['backfill_status' => 'complete', 'deploy_phase' => 'enabled', 'deploy_status' => 'enabled', 'updated_at' => now(), 'created_at' => now()],
            );
            foreach ([MatchNormativesStage::class, \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource::class, \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService::class, \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow::class] as $abstract) {
                $this->app->forgetInstance($abstract);
            }
            $matchContext = $this->matchContext($session, $planResult->output, $plan, $graph);
            $matchResult = $this->app->make(MatchNormativesStage::class)->execute($matchContext);
            $matchedItems = $this->workItems($matchResult->transientData['local_estimates']);
            $matched = collect($matchedItems)->firstWhere('normative_retrieval.status', 'retrieval_only');
            self::assertIsArray($matched);
            self::assertSame($version, $matched['normative_retrieval']['dataset_version']);
            self::assertSame('normative-combined-v1', $matched['normative_retrieval']['scoring_version']);
            self::assertSame('08-01-001-01', $matched['normative_rate_code']);
            self::assertSame($normId, $matched['normative_match']['norm_id']);
            self::assertNotSame($competingNormId, $matched['normative_match']['norm_id']);
            self::assertSame($version, $matched['normative_match']['dataset_version']['version_key']);
            self::assertSame('Кирпич керамический', $matched['materials'][0]['name']);
            self::assertSame(1.2, $matched['materials'][0]['quantity_per_unit']);
            self::assertSame(15.0, $matched['materials'][0]['unit_price']);
            self::assertSame(
                round($matched['materials'][0]['quantity'] * 15, 2),
                $matched['materials'][0]['total_price'],
            );
            self::assertSame(['Подготовка основания', 'Кладка стены'], $matched['work_composition']);
            self::assertNotEmpty($matched['materials']);
            $changedPin = [...$pin, 'dataset_version' => $version.'-changed'];
            self::assertNotSame(
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $pin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $changedPin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
            );

            $before = EstimateGenerationSession::query()->where('organization_id', $organization->id)->count();
            $this->postJson($url, ['description' => 'test', 'normative_dataset_version' => 'foreign-version'])->assertUnprocessable();
            self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());
        } finally {
            EstimateGenerationSession::query()->where('organization_id', $organization->id)->delete();
            DB::table('estimate_dataset_versions')->whereIn('id', $fixtureDatasetIds)->delete();
            $dataset->delete();
            DB::table('modules')->where('id', $moduleId)->delete();
            $project->delete();
            $unauthorized?->delete();
            $user->delete();
            $organization->delete();
        }
    }

    private function analysis(array $regionalContext): array
    {
        return [
            'object' => [
                'object_type' => 'house',
                'building_type' => 'house',
                'description' => 'Жилой дом с наружными кирпичными стенами',
                'area' => 100,
                'floors' => 1,
                'region_code' => 'RU-MOS',
            ],
            'document_context' => [
                'context_text' => 'Кладка кирпичных стен площадью 100 м2',
                'facts_summary' => ['total_area_m2' => 100],
                'quantity_takeoffs' => [[
                    'quantity_key' => 'walls.external_volume',
                    'name' => 'Объём кладки наружных стен',
                    'quantity' => 38,
                    'unit' => 'м3',
                    'confidence' => 0.98,
                    'source_refs' => [],
                    'normalized_payload' => ['review_required' => false],
                ]],
            ],
            'source_documents' => [[
                'id' => 1,
                'filename' => 'ведомость.txt',
                'status' => 'ready',
                'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'project_documentation'],
                'text' => 'ГЭСН 08-01-001-01 Кладка наружных кирпичных стен 38 м3',
            ]],
            'regional_context' => $regionalContext,
        ];
    }

    private function planContext(
        EstimateGenerationSession $session,
        array $analysis,
        PipelineDefinitionGraph $graph,
    ): PipelineContext {
        $understand = $this->priorOutput(ProcessingStage::UnderstandObject, ['analysis' => $analysis], $graph);
        $quantityPayload = [
            'quantity_learning_hints' => [],
            'quantity_coverage_warnings' => [],
            'building_quantities' => [],
        ];
        $quantities = $this->priorOutput(ProcessingStage::ExtractQuantities, $quantityPayload, $graph);
        $dependencies = [
            ProcessingStage::UnderstandObject->value => $understand->version,
            ProcessingStage::ExtractQuantities->value => $quantities->version,
        ];
        $base = 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($session->input_payload));
        $definition = $graph->get(ProcessingStage::PlanWorkItems);

        return new PipelineContext(
            (int) $session->id,
            (int) $session->organization_id,
            (int) $session->project_id,
            1,
            PipelineInputVersion::for($definition, $base, $dependencies),
            'generating',
            priorOutputs: new PipelinePriorOutputs(
                [ProcessingStage::UnderstandObject->value => $understand, ProcessingStage::ExtractQuantities->value => $quantities],
                [
                    ProcessingStage::UnderstandObject->value => ['analysis' => $analysis],
                    ProcessingStage::ExtractQuantities->value => $quantityPayload,
                ],
            ),
            generationAttemptId: '00000000-0000-4000-8000-000000000001',
            baseInputVersion: $base,
            stage: ProcessingStage::PlanWorkItems,
            dependencyVersions: $dependencies,
        );
    }

    private function matchContext(
        EstimateGenerationSession $session,
        PipelineStageOutput $planOutput,
        array $plan,
        PipelineDefinitionGraph $graph,
    ): PipelineContext {
        $dependencies = [ProcessingStage::PlanWorkItems->value => $planOutput->version];
        $base = 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($session->input_payload));
        $definition = $graph->get(ProcessingStage::MatchNormatives);

        return new PipelineContext(
            (int) $session->id,
            (int) $session->organization_id,
            (int) $session->project_id,
            1,
            PipelineInputVersion::for($definition, $base, $dependencies),
            'generating',
            priorOutputs: new PipelinePriorOutputs(
                [ProcessingStage::PlanWorkItems->value => $planOutput],
                [ProcessingStage::PlanWorkItems->value => $plan],
            ),
            generationAttemptId: '00000000-0000-4000-8000-000000000001',
            baseInputVersion: $base,
            stage: ProcessingStage::MatchNormatives,
            dependencyVersions: $dependencies,
        );
    }

    private function priorOutput(ProcessingStage $stage, array $payload, PipelineDefinitionGraph $graph): PipelineStageOutput
    {
        PipelineStagePayload::from($stage, $payload);
        $canonical = CanonicalPipelineJson::encode($payload);
        $dependencies = [];
        foreach ($graph->get($stage)->dependencies as $dependency) {
            $dependencies[$dependency->value] = 'sha256:'.hash('sha256', $dependency->value);
        }

        return PipelineStageOutput::create(
            $graph->get($stage),
            'sha256:'.hash('sha256', $stage->value.'-input'),
            $dependencies,
            new PipelineArtifactReference(
                'memory_json_v1',
                'contract/'.$stage->value,
                'sha256:'.hash('sha256', $canonical),
                strlen($canonical),
            ),
        );
    }

    private function seedCompatibleNorm(int $datasetId, int $priceDatasetId, string $version, array $workItem): int
    {
        $normId = $this->seedNorm($datasetId, $version, 'Кладка наружных кирпичных стен');
        $intent = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier::class)
            ->classify($workItem, ['scope_type' => 'walls']);
        DB::table('estimate_norms')->where('id', $normId)->update([
            'canonical_unit' => $workItem['unit'],
            'unit_dimension' => $intent->expectedDimensions[0],
            'material' => $intent->material,
            'technology' => $intent->action,
            'structure' => $intent->scope,
            'section_code' => $intent->preferredSectionPrefixes[0],
            'object_type' => $intent->object,
            'updated_at' => now(),
        ]);
        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'construction_resource_id' => null,
            'resource_code' => '04.3.01.01-0001',
            'resource_name' => 'Кирпич керамический',
            'unit' => 'шт',
            'quantity' => 1.2,
            'resource_type' => 'material',
            'raw_payload' => json_encode(['source' => 'contract'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $priceDatasetId,
            'construction_resource_id' => null,
            'resource_code' => '04.3.01.01-0001',
            'resource_name' => 'Кирпич керамический',
            'unit' => 'шт',
            'base_price' => 15,
            'price_type' => 'material',
            'raw_payload' => json_encode(['source' => 'contract'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $normId;
    }

    private function seedNorm(int $datasetId, string $version, string $name): int
    {
        $collection = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $datasetId,
            'code' => '08-'.$version,
            'name' => 'Каменные конструкции',
            'norm_type' => 'gesn',
            'source_file' => $version.'.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collection,
            'code' => '08-01-001-01',
            'name' => $name,
            'unit' => 'м3',
            'canonical_unit' => 'м3',
            'unit_dimension' => 'volume',
            'material' => 'кирпич',
            'technology' => 'кладка',
            'structure' => 'стена',
            'object_type' => 'house',
            'region_code' => 'RU-MOS',
            'section_code' => '08',
            'section_name' => 'Каменные конструкции',
            'work_composition' => json_encode(['Подготовка основания', 'Кладка стены'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'valid_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dataset(string $sourceType, string $version): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => $sourceType,
            'version_key' => $version,
            'bucket' => 'contract',
            'prefix' => $version,
            'status' => 'parsed',
            'files_count' => 0,
            'rows_read' => 0,
            'rows_imported' => 0,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function workItems(array $localEstimates): array
    {
        $items = [];
        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                array_push($items, ...$section['work_items']);
            }
        }

        return $items;
    }
}
