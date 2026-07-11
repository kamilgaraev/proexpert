<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
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
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityGateService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStageFunctionalTest extends TestCase
{
    #[Test]
    public function nine_real_stage_boundaries_consume_prior_outputs_and_produce_versioned_data(): void
    {
        $gateway = new class implements GenerationPipelineDataGateway
        {
            public function source(PipelineContext $context): array
            {
                return ['input' => ['description' => 'Небольшой одноэтажный дом 80 м2'], 'documents' => [], 'user_id' => 7];
            }
        };
        $matcher = $this->createMock(ResourceAssemblyService::class);
        $matcher->method('enrich')->willReturnCallback(static fn (array $items): array => $items);
        $artifacts = new InMemoryPipelineArtifactStore;
        $results = new StageResultFactory($artifacts);
        $stages = [
            new UnderstandDocumentsStage($gateway, $results),
            new UnderstandObjectStage(new ConstructionSemanticParser, $results),
            new ExtractQuantitiesStage(new EstimateGenerationQuantityLearningEvidenceService, $results),
            new PlanWorkItemsStage(
                new PackagePlannerService,
                new EstimateDecompositionService,
                new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
                $results,
            ),
            new MatchNormativesStage($matcher, $results),
            new AssembleResourcesStage(new AssembleMatchedResources, $results),
            new ResolvePricesStage(new EstimatePricingService, $results),
            new BuildDraftStage($results),
            new ValidateDraftStage(new EstimateValidationService, new EstimateGenerationQualityGateService, $results),
        ];
        $outputs = [];
        $context = new PipelineContext(1, 2, 3, 4, 'attempt-1', 'generating');

        foreach ($stages as $index => $stage) {
            $payloads = [];
            foreach ($outputs as $key => $reference) {
                $payloads[$key] = $artifacts->read($context, $reference);
            }
            $result = $stage->execute($context->withPriorOutputs(new PipelinePriorOutputs($outputs, $payloads)));
            self::assertSame(ProcessingStage::cases()[$index], $result->stage);
            self::assertNotNull($result->output);
            self::assertSame($result->outputVersion, $result->output->version);
            self::assertSame('memory_json_v1', $result->output->data['artifact_kind']);
            $outputs[$result->stage->value] = $result->output;
        }

        $validated = $artifacts->read($context, $outputs[ProcessingStage::ValidateDraft->value]);
        self::assertArrayHasKey('draft', $validated);
        self::assertArrayHasKey('requires_review', $validated);
        self::assertArrayHasKey('quality_summary', $validated['draft']);
    }
}
