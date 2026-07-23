<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandObjectStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnderstandObjectStageDocumentAreaTest extends TestCase
{
    #[Test]
    public function exact_document_area_evidence_is_carried_into_analysis(): void
    {
        $area = ['amount' => '180.000000', 'evidence_id' => 901, 'confidence' => 0.95, 'floor_count' => 2];
        $gateway = new class($area) implements GenerationPipelineDataGateway
        {
            public function __construct(private array $area) {}

            public function manifest(PipelineContext $context): array
            {
                return ['base_input_version' => $context->baseInputVersion, 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null];
            }

            public function source(PipelineContext $context): array
            {
                return [
                    'input' => ['description' => 'Жилой дом'],
                    'documents' => [],
                    'user_id' => 7,
                    'normalized_building_model' => null,
                    'document_total_area' => $this->area,
                ];
            }
        };
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('c', 64);
        $dependency = PipelineStageOutput::create(
            $graph->get(ProcessingStage::UnderstandDocuments),
            $base,
            [],
            new PipelineArtifactReference('memory_json_v1', 'memory/source', $base, 1),
        );
        $prior = new PipelinePriorOutputs(
            ['understand_documents' => $dependency],
            ['understand_documents' => ['documents_summary' => []]],
        );
        $context = new PipelineContext(
            1, 2, 3, 0, $base, 'generating', priorOutputs: $prior,
            generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base,
            stage: ProcessingStage::UnderstandObject, dependencyVersions: ['understand_documents' => $dependency->version],
        );
        $stage = new UnderstandObjectStage(
            new ConstructionSemanticParser,
            $gateway,
            new StageResultFactory(new InMemoryPipelineArtifactStore, $graph),
        );

        $result = $stage->execute($context);

        self::assertSame($area, $result->transientData['analysis']['document_total_area'] ?? null);
    }
}
