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
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityGateService;
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
                return ['input' => ['description' => 'Одноэтажный дом 80 м2'], 'documents' => [], 'user_id' => 7];
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
            new ExtractQuantitiesStage(new EstimateGenerationQuantityLearningEvidenceService, $results),
            new PlanWorkItemsStage(new PackagePlannerService, new EstimateDecompositionService, new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService), new NormativeContextPinResolver, $results),
            new MatchNormativesStage($matcher, $workflow, new NormativeWorkIntentFactory, $results),
            new AssembleResourcesStage(new AssembleMatchedResources, $results),
            new ResolvePricesStage(new EstimatePricingService, $results),
            new BuildDraftStage($results),
            new ValidateDraftStage(new EstimateValidationService, new EstimateGenerationQualityGateService, $results),
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
        $payload = $state->priorOutputs($seed)->payload(ProcessingStage::ValidateDraft);
        self::assertArrayHasKey('quality_summary', $payload['draft']);
    }
}
