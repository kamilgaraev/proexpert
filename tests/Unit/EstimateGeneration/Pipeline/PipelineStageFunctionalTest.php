<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePlanResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\AssembleResourcesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\BuildDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ExtractQuantitiesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ResolvePricesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandDocumentsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandObjectStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Planning\AiResidentialWorkCompositionAdvisor;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkCompositionLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStageFunctionalTest extends TestCase
{
    #[Test]
    public function nine_real_stage_boundaries_consume_exact_typed_dependencies(): void
    {
        $gateway = new class implements GenerationPipelineDataGateway
        {
            public function manifest(PipelineContext $context): array
            {
                return ['base_input_version' => (string) $context->baseInputVersion, 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null];
            }

            public function source(PipelineContext $context): array
            {
                return ['input' => [
                    'description' => 'Полное строительство одноэтажного жилого дома под ключ, включая отопление и канализацию',
                    'area' => 80,
                    'generation_mode' => 'ai_assisted',
                ], 'documents' => [], 'user_id' => 7, 'document_total_area' => [
                    'amount' => '80.000000',
                    'evidence_id' => 701,
                    'confidence' => 0.95,
                    'floor_count' => 1,
                ], 'normalized_building_model' => (new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData(
                    unit: 'm',
                    scaleStatus: 'confirmed',
                    scaleMetersPerUnit: 1.0,
                    floors: [new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData(
                        key: 'floor-1',
                        elevationM: 0.0,
                        heightM: 3.0,
                        rooms: [new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData(
                            key: 'room-1',
                            name: 'Жилая комната',
                            polygon: [[0, 0], [10, 0], [10, 8], [0, 8]],
                            evidenceIds: [701],
                            confidence: 0.95,
                            geometryCertainty: 'confirmed',
                        )],
                        walls: [],
                        openings: [],
                        engineeringElements: [],
                        evidenceIds: [701],
                        confidence: 0.95,
                        geometryCertainty: 'confirmed',
                    )],
                    assumptions: [],
                    modelVersion: 'building-model:v1',
                ))->toArray()];
            }
        };
        $matcher = $this->createMock(ResourceAssemblyService::class);
        $matcher->method('enrich')->willReturnCallback(static fn (array $items): array => $items);
        $source = new class implements NormativeCandidateSource
        {
            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                return [];
            }
        };
        $reranker = new class implements NormativeCandidateRerankerInterface
        {
            public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
            {
                throw new \LogicException('Empty retrieval must not rerank.');
            }
        };
        $workflow = new NormativeMatchingWorkflow(new NormativeRetrievalService($source, new NormativeHardGate, 16, null), $reranker);
        $artifacts = new InMemoryPipelineArtifactStore;
        $graph = PipelineDefinitionGraph::standard();
        $results = new StageResultFactory($artifacts, $graph);
        $stages = [
            new UnderstandDocumentsStage($gateway, $results),
            new UnderstandObjectStage(new ConstructionSemanticParser, $gateway, $results),
            new ExtractQuantitiesStage(
                new EstimateGenerationQuantityLearningEvidenceService,
                $results,
                new \App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory(
                    new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository,
                ),
            ),
            new PlanWorkItemsStage(new \App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler(new PackagePlannerService, new EstimateDecompositionService, new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService), new NormativeContextPinResolver), $results, new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer(new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository), new AiResidentialWorkCompositionAdvisor(new class implements WorkCompositionLlmClient
            {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function chat(array $messages, PipelineContext $context, string $candidateSetHash): array
                {
                    return [
                        'content' => json_encode([
                            'schema_version' => AiResidentialWorkCompositionAdvisor::SCHEMA_VERSION,
                            'default_decision' => [
                                'status' => 'include',
                                'reason_codes' => ['residential_scope'],
                                'confidence' => 0.9,
                            ],
                            'exceptions' => [],
                            'scope_decisions' => [
                                [
                                    'key' => 'heating_source',
                                    'option' => 'electric_boiler',
                                    'status' => 'preliminary',
                                    'confidence' => 0.6,
                                    'evidence_ids' => [],
                                ],
                                [
                                    'key' => 'wastewater_destination',
                                    'option' => 'septic',
                                    'status' => 'preliminary',
                                    'confidence' => 0.6,
                                    'evidence_ids' => [],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                        'model' => 'test-model',
                        'usage_available' => true,
                    ];
                }
            })),
            new MatchNormativesStage($matcher, $workflow, new NormativeWorkIntentFactory, $results),
            new AssembleResourcesStage(new AssembleMatchedResources, $results),
            new ResolvePricesStage(new EstimatePricingService, $results),
            new BuildDraftStage($results),
            new ValidateDraftStage(new EstimateValidationService, new DraftReadinessProjector, $results),
        ];
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = '00000000-0000-4000-8000-000000000001';
        $state = new InMemoryPipelineStateStore($artifacts);
        $resolver = new PipelinePlanResolver($graph, $state, $state);
        $runner = new PipelineRunner(
            new PipelineRegistry($stages), $state,
            new FailureRecorder(new class implements FailureStore
            {
                public function record(FailureData $failure, DateTimeImmutable $seenAt): void {}

                public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
                {
                    return true;
                }

                public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
                {
                    return 0;
                }
            }),
            new class implements FailureWorkflowHandler
            {
                public function handle(FailureData $failure, ?int $expectedStateVersion = null): void {}
            },
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-11T10:00:00+00:00'),
        );
        $seed = new PipelineContext(1, 2, 3, 4, $base, 'generating', generationAttemptId: $attempt, baseInputVersion: $base);
        for ($invocation = 0; $invocation < 9; $invocation++) {
            $context = $resolver->next($seed);
            self::assertNotNull($context);
            $result = $runner->runNext($context);
            self::assertSame($context->stage, $result?->stage);
        }
        self::assertNull($resolver->next($seed));
        $planned = $state->priorOutputs($seed)->payload(ProcessingStage::PlanWorkItems);
        $floorItems = [];
        $scopeDecisionItems = [];
        $plannedItems = [];
        foreach ($planned['local_estimates'] as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                foreach ($section['work_items'] as $workItem) {
                    $quantityKey = $workItem['metadata']['quantity_key'] ?? null;
                    if (is_string($quantityKey) && $quantityKey !== '') {
                        $plannedItems[$quantityKey] = $workItem;
                    }
                    if ($quantityKey === 'finish.floor') {
                        $floorItems[] = $workItem;
                    }
                    if (in_array($quantityKey, ['heating.unit', 'sewerage.outlet_route'], true)) {
                        $scopeDecisionItems[$quantityKey] = $workItem;
                    }
                }
            }
        }
        self::assertNotEmpty($floorItems);
        self::assertCount(2, $scopeDecisionItems);
        self::assertArrayHasKey('heating.unit', $scopeDecisionItems);
        self::assertArrayHasKey('sewerage.outlet_route', $scopeDecisionItems);
        foreach ([
            'electrical.panel',
            'electrical.outlets',
            'electrical.switches',
            'lighting.fixtures',
        ] as $quantityKey) {
            self::assertArrayHasKey($quantityKey, $plannedItems);
            self::assertSame('pcs', $plannedItems[$quantityKey]['unit']);
            self::assertSame($quantityKey, $plannedItems[$quantityKey]['quantity_evidence']['key'] ?? null);
            self::assertIsInt($plannedItems[$quantityKey]['quantity_evidence_id'] ?? null);
        }
        foreach ($scopeDecisionItems as $quantityKey => $workItem) {
            self::assertSame($quantityKey, $workItem['quantity_evidence']['key'] ?? null, $quantityKey);
            self::assertIsInt($workItem['quantity_evidence_id'] ?? null, $quantityKey);
            self::assertGreaterThan(0, $workItem['quantity_evidence_id'], $quantityKey);
            self::assertArrayNotHasKey('quantity_mapping_missing', array_flip($workItem['validation_flags'] ?? []), $quantityKey);
        }
        self::assertSame('completed', $planned['package_plan']['work_composition_advice']['status'] ?? null);
        $extracted = $state->priorOutputs($seed)->payload(ProcessingStage::ExtractQuantities);
        $floorAreaRows = array_values(array_filter(
            $extracted['building_quantities']['quantities'] ?? [],
            static fn (mixed $quantity): bool => is_array($quantity) && ($quantity['key'] ?? null) === 'floor_area',
        ));
        self::assertNotEmpty($floorAreaRows);
        self::assertSame('80.000000', $floorAreaRows[0]['amount']);

        $payload = $state->priorOutputs($seed)->payload(ProcessingStage::ValidateDraft);
        self::assertArrayHasKey('quality_summary', $payload['draft']);
    }
}
