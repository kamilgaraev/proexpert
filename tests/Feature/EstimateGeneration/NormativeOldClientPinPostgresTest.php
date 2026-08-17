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
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EstimateGeneration\EstimateGenerationPostgresTestCase;

#[Group('postgres-contract')]
final class NormativeOldClientPinPostgresTest extends EstimateGenerationPostgresTestCase
{
    public function test_old_client_post_persists_server_pin_and_rejects_missing_unapproved_or_mismatch(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);
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
        $priceDatasetId = $this->dataset('fsbc', 'prices-'.$version);
        $normId = $this->seedCompatibleNorm((int) $dataset->id, $priceDatasetId, $version, [
            'name' => 'Кладка наружных кирпичных стен',
            'quantity_key' => 'walls.external_volume',
            'unit' => 'м3',
        ]);
        $regionalContext = $this->seedRegionalPriceContext($priceDatasetId, 'prices-'.$version);
        DB::table('modules')->insert([
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
        config()->set('web_auth.keys.admin', str_repeat('a', 64));
        $requestPayload = [
            'description' => 'Кладка кирпичных стен',
            'area' => 100,
            'region_id' => $regionalContext['region_id'],
            'price_zone_id' => $regionalContext['price_zone_id'],
            'period_id' => $regionalContext['period_id'],
            'estimate_regional_price_version_id' => $regionalContext['estimate_regional_price_version_id'],
        ];

        $unauthorized = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);
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
        UserRoleAssignment::assignRole(
            $unauthorized,
            'admin_viewer',
            AuthorizationContext::getOrganizationContext((int) $organization->id),
        );
        $unauthorizedHeaders = $this->adminHeaders($unauthorized, $organization);
        $unauthorizedResponse = $this->withHeaders($unauthorizedHeaders)
            ->postJson($url, $requestPayload);
        self::assertSame(403, $unauthorizedResponse->status(), $unauthorizedResponse->getContent());
        self::assertSame(0, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());

        $authorizedHeaders = $this->adminHeaders($user, $organization);
        $response = $this->withHeaders($authorizedHeaders)
            ->postJson($url, $requestPayload);
        $response->assertCreated();
        $session = EstimateGenerationSession::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        self::assertSame($version, $session->input_payload['regional_context']['normative_dataset_version']);
        self::assertSame('2026-07-12', $session->input_payload['regional_context']['business_date']);
        $artifacts = new InMemoryPipelineArtifactStore;
        $graph = PipelineDefinitionGraph::standard();
        $this->app->instance(StageResultFactory::class, new StageResultFactory($artifacts, $graph));
        $this->app->instance(
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel::class,
            new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel
            {
                public function compose(
                    \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput $input,
                    callable $onPhysicalAttemptReserved,
                ): array {
                    $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

                    return ['work_intents' => array_map(static fn (array $candidate): array => [
                        'kind' => 'existing',
                        'candidate_id' => $candidate['candidate_id'],
                        'work_key' => null,
                        'name' => null,
                        'derived_quantity_id' => null,
                        'source_fact_ids' => $candidate['source_fact_ids'],
                        'technology_package_candidate' => $candidate['technology_package_candidate'],
                        'assumptions' => [],
                        'exclusions' => [],
                        'missing_document_recommendations' => [],
                    ], $input->candidates)];
                }
            },
        );
        $this->app->forgetInstance(
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::class,
        );
        $this->app->forgetInstance(PlanWorkItemsStage::class);
        $analysis = $this->analysis($session->input_payload['regional_context']);
        $planContext = $this->planContext($session, $analysis, $graph);
        $planResult = $this->app->make(PlanWorkItemsStage::class)->execute($planContext);
        $plan = $planResult->transientData;
        self::assertIsArray($plan);
        self::assertNotEmpty($plan['local_estimates']);
        $pin = $plan['normative_context_pin'];
        self::assertSame('pinned', $pin['status'], json_encode($pin, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame($session->input_payload['regional_context']['normative_dataset_version'], $pin['dataset_version']);
        self::assertSame($session->input_payload['regional_context']['business_date'], $pin['applicability_date']);

        $changedAnalysis = $analysis;
        $changedAnalysis['regional_context']['business_date'] = '2026-07-13';
        $changedPlanContext = $this->planContext($session, $changedAnalysis, $graph);
        $changedPlan = $this->app->make(PlanWorkItemsStage::class)->execute($changedPlanContext);
        self::assertNotSame($planContext->inputVersion, $changedPlanContext->inputVersion);
        self::assertNotSame($planResult->outputVersion, $changedPlan->outputVersion);

        $before = EstimateGenerationSession::query()->where('organization_id', $organization->id)->count();
        $this->withHeaders($authorizedHeaders)->postJson(
            $url,
            ['description' => 'test', 'normative_dataset_version' => 'foreign-version'],
        )->assertUnprocessable();
        self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());
    }

    private function adminHeaders(User $user, Organization $organization): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'PostgreSQL contract harness',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $tokens = $this->app->make(WebAuthTokenService::class)->issue(
            $user,
            'admin',
            $sessionUuid,
            (int) $organization->id,
            false,
        );

        return [
            'Authorization' => 'Bearer '.$tokens->accessToken,
            'Accept' => 'application/json',
            'Origin' => 'https://admin.1мост.рф',
        ];
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
            'stage6_generation_context' => [],
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
        $this->alignNormWithWorkItem($normId, $workItem);
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

    private function alignNormWithWorkItem(int $normId, array $workItem): void
    {
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

    private function seedRegionalPriceContext(int $priceDatasetId, string $version): array
    {
        $now = now();
        $regionId = (int) DB::table('estimate_regions')->insertGetId([
            'code' => 'RU-MOS-'.$priceDatasetId,
            'name' => 'Московская область',
            'fgiscs_subject_id' => 500000 + $priceDatasetId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceZoneId = (int) DB::table('estimate_price_zones')->insertGetId([
            'estimate_region_id' => $regionId,
            'name' => 'Московская область',
            'fgiscs_price_zone_id' => 5000000 + $priceDatasetId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = (int) DB::table('estimate_price_periods')->insertGetId([
            'fgiscs_period_id' => 20260200 + $priceDatasetId,
            'name' => 'II квартал 2026',
            'year' => 2026,
            'quarter' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $regionalPriceVersionId = (int) DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'fgiscs',
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'version_key' => $version,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('estimate_resource_prices')->where('dataset_version_id', $priceDatasetId)->update([
            'regional_price_version_id' => $regionalPriceVersionId,
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'updated_at' => $now,
        ]);
        DB::table('estimate_regional_price_versions')->where('id', $regionalPriceVersionId)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);

        return [
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'estimate_regional_price_version_id' => $regionalPriceVersionId,
        ];
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
}
