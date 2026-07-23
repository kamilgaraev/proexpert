<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ExtractQuantitiesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;
use PHPUnit\Framework\TestCase;

final class ExtractQuantitiesStageMapperIntegrationTest extends TestCase
{
    public function test_runtime_stage_invokes_mapper_and_emits_canonical_quantities(): void
    {
        $mapper = new class implements BuildingModelQuantityInputMapper
        {
            public int $calls = 0;

            public function map(NormalizedBuildingModelData $model): array
            {
                $this->calls++;

                return (new NormalizedBuildingModelQuantityInputMapper)->map($model);
            }
        };
        $model = new NormalizedBuildingModelData('m', 'confirmed', 1.0, [
            new FloorData('floor-1', 0.0, 2.8, [new RoomData('room-1', null, [[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]], [11], 0.9, 'confirmed')], [], [], [], [11], 0.9, 'confirmed'),
        ], [], 'building-model:v1');
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('a', 64);
        $dependency = PipelineStageOutput::create(
            $graph->get(ProcessingStage::UnderstandObject), $base,
            ['understand_documents' => $base],
            new PipelineArtifactReference('memory_json_v1', 'memory/source', $base, 1),
        );
        $prior = new PipelinePriorOutputs(
            ['understand_object' => $dependency],
            ['understand_object' => ['analysis' => ['normalized_building_model' => $model->toArray()]]],
        );
        $context = new PipelineContext(
            1, 2, 3, 0, $base, 'generating', priorOutputs: $prior,
            generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base,
            stage: ProcessingStage::ExtractQuantities, dependencyVersions: ['understand_object' => $dependency->version],
        );
        $artifacts = new InMemoryPipelineArtifactStore;
        $stage = new ExtractQuantitiesStage(
            new EstimateGenerationQuantityLearningEvidenceService,
            new StageResultFactory($artifacts, $graph),
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory(
                new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository,
            ),
            $mapper,
        );

        $result = $stage->execute($context);

        self::assertSame(1, $mapper->calls);
        self::assertNotNull($result->transientData);
        $quantities = array_column($result->transientData['building_quantities']['quantities'], null, 'key');
        self::assertSame('12.000000', $quantities['floor_area']['amount']);
    }

    public function test_exact_document_area_evidence_overrides_polygon_derived_area(): void
    {
        $model = new NormalizedBuildingModelData('m', 'confirmed', 1.0, [
            new FloorData('floor-1', null, null, [
                new RoomData('room-1', 'Санузел (1 этаж)', [[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]], [11], 0.9, 'confirmed'),
            ], [], [], [], [11], 0.9, 'confirmed'),
        ], [], 'building-model:v1');
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('b', 64);
        $dependency = PipelineStageOutput::create(
            $graph->get(ProcessingStage::UnderstandObject), $base,
            ['understand_documents' => $base],
            new PipelineArtifactReference('memory_json_v1', 'memory/source', $base, 1),
        );
        $prior = new PipelinePriorOutputs(
            ['understand_object' => $dependency],
            ['understand_object' => ['analysis' => [
                'normalized_building_model' => $model->toArray(),
                'document_total_area' => [
                    'amount' => '180.000000', 'evidence_id' => 901, 'confidence' => 0.95, 'floor_count' => 1,
                ],
            ]]],
        );
        $context = new PipelineContext(
            1, 2, 3, 0, $base, 'generating', priorOutputs: $prior,
            generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base,
            stage: ProcessingStage::ExtractQuantities, dependencyVersions: ['understand_object' => $dependency->version],
        );
        $artifacts = new InMemoryPipelineArtifactStore;
        $stage = new ExtractQuantitiesStage(
            new EstimateGenerationQuantityLearningEvidenceService,
            new StageResultFactory($artifacts, $graph),
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory(
                new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository,
            ),
        );

        $result = $stage->execute($context);

        self::assertNotNull($result->transientData);
        $quantities = array_column($result->transientData['building_quantities']['quantities'], null, 'key');
        self::assertSame('180.000000', $quantities['floor_area']['amount']);
        self::assertSame('evidenced', $quantities['floor_area']['source']);
        self::assertSame(['901'], $quantities['floor_area']['evidence_ids']);
    }
}
