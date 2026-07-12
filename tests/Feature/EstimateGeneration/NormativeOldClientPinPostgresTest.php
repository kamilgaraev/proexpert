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
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

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
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $version = 'old-client-'.strtolower((string) str()->ulid());
        $dataset = EstimateDatasetVersion::query()->create([
            'source_type' => EstimateSourceType::FSNB_2022,
            'version_key' => $version, 'bucket' => 'contract', 'prefix' => $version,
            'status' => EstimateImportStatus::PARSED,
        ]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(NormativePinClock::class, new class implements NormativePinClock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-12T10:00:00+03:00');
            }
        });
        config()->set('estimate-generation.normative_matching.approved_dataset_version', $version);
        $this->actingAs($user, 'api_admin');
        $url = "/api/v1/admin/projects/{$project->id}/estimate-generation/sessions";

        try {
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

            $normId = $this->seedCompatibleNorm((int) $dataset->id, $version);
            DB::table('estimate_normative_retrieval_rollouts')->updateOrInsert(
                ['schema_version' => 'normative-retrieval-v1'],
                ['backfill_status' => 'complete', 'deploy_phase' => 'enabled', 'deploy_status' => 'enabled', 'updated_at' => now(), 'created_at' => now()],
            );
            $matcher = Mockery::mock(ResourceAssemblyService::class);
            $matcher->shouldReceive('enrich')->andReturnUsing(static function (array $items, array $context) use ($normId): array {
                if (! isset($context['selected_norm_id'])) {
                    return $items;
                }
                self::assertSame($normId, (int) $context['selected_norm_id']);
                $items[0]['normative_rate_code'] = '08-01-001-01';
                $items[0]['materials'] = [['name' => 'Кирпич', 'quantity' => 120.0]];

                return $items;
            });
            $this->app->instance(ResourceAssemblyService::class, $matcher);
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
            self::assertNotEmpty($matched['materials']);
            $changedPin = [...$pin, 'dataset_version' => $version.'-changed'];
            self::assertNotSame(
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $pin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $changedPin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
            );

            $before = EstimateGenerationSession::query()->where('organization_id', $organization->id)->count();
            $this->postJson($url, ['description' => 'test', 'normative_dataset_version' => 'foreign-version'])->assertUnprocessable();
            self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());

            config()->set('estimate-generation.normative_matching.approved_dataset_version', null);
            $this->app->forgetInstance(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeDatasetPinPolicy::class);
            $this->postJson($url, ['description' => 'test'])->assertUnprocessable();
            self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());
        } finally {
            EstimateGenerationSession::query()->where('organization_id', $organization->id)->delete();
            $dataset->delete();
            $project->delete();
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
            'document_context' => ['context_text' => 'Кладка кирпичных стен площадью 100 м2'],
            'regional_context' => $regionalContext,
        ];
    }

    private function planContext(
        EstimateGenerationSession $session,
        array $analysis,
        PipelineDefinitionGraph $graph,
    ): PipelineContext {
        $understand = $this->priorOutput(ProcessingStage::UnderstandObject, ['analysis' => $analysis], $graph);
        $quantities = $this->priorOutput(ProcessingStage::ExtractQuantities, ['quantity_learning_hints' => []], $graph);
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
                [ProcessingStage::UnderstandObject->value => ['analysis' => $analysis], ProcessingStage::ExtractQuantities->value => ['quantity_learning_hints' => []]],
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

    private function seedCompatibleNorm(int $datasetId, string $version): int
    {
        $collection = DB::table('estimate_norm_collections')->insertGetId([
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
            'name' => 'Кладка наружных кирпичных стен',
            'unit' => 'м2',
            'canonical_unit' => 'м2',
            'unit_dimension' => 'area',
            'material' => 'кирпич',
            'technology' => 'кладка',
            'structure' => 'стена',
            'object_type' => 'house',
            'section_code' => '08',
            'valid_from' => '2026-01-01',
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
