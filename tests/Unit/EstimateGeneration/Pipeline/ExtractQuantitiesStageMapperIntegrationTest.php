<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
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

    public function test_manual_room_area_correction_overrides_documented_room_annotation_in_final_quantities(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $roomArea = $evidence->insertOrGet(new EvidenceData(
            1,
            2,
            3,
            EvidenceType::Extracted,
            EvidenceSourceType::DocumentUnit,
            'document:501',
            'sha256:'.str_repeat('c', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:room-1'],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95,
            EvidenceProducer::DrawingAnalyzer->value,
            'model:v2',
        ));
        $documentTotalArea = $evidence->insertOrGet(new EvidenceData(
            1,
            2,
            3,
            EvidenceType::Extracted,
            EvidenceSourceType::DocumentUnit,
            'document:501',
            'sha256:'.str_repeat('e', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:total-area'],
            ['fact_key' => 'total_area_m2', 'fact_value' => 180.0, 'unit' => 'm2'],
            0.95,
            EvidenceProducer::DrawingAnalyzer->value,
            'model:v2',
        ));
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [
            new FloorData('floor-1', null, null, [
                new RoomData('room-1', 'Kitchen 42,7', null, [$roomArea->id], 0.95, 'unknown'),
            ], [], [], [], [$roomArea->id], 0.95, 'unknown'),
        ], [], 'building-model:v1');
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('d', 64);
        $dependency = PipelineStageOutput::create(
            $graph->get(ProcessingStage::UnderstandObject),
            $base,
            ['understand_documents' => $base],
            new PipelineArtifactReference('memory_json_v1', 'memory/source', $base, 1),
        );
        $prior = new PipelinePriorOutputs(
            ['understand_object' => $dependency],
            ['understand_object' => ['analysis' => [
                'object' => ['floors' => 1],
                'normalized_building_model' => $model->toArray(),
                'document_total_area' => [
                    'amount' => '180.000000',
                    'evidence_id' => $documentTotalArea->id,
                    'confidence' => 0.95,
                    'floor_count' => 1,
                ],
                'effective_project_model_values' => [[
                    'entity_stable_key' => 'room-1',
                    'assertion_stable_key' => 'room-1:area',
                    'assertion_type' => 'area',
                    'value' => ['value' => 65.0, 'unit' => 'm2'],
                    'correction_stable_key' => 'correction:room-1:area',
                ]],
            ]]],
        );
        $context = new PipelineContext(
            1, 2, 3, 0, $base, 'generating', priorOutputs: $prior,
            generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base,
            stage: ProcessingStage::ExtractQuantities, dependencyVersions: ['understand_object' => $dependency->version],
        );
        $stage = new ExtractQuantitiesStage(
            new EstimateGenerationQuantityLearningEvidenceService,
            new StageResultFactory(new InMemoryPipelineArtifactStore, $graph),
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory($evidence),
        );

        $result = $stage->execute($context);

        self::assertNotNull($result->transientData);
        $quantities = array_column($result->transientData['building_quantities']['quantities'], null, 'key');
        self::assertSame('65.000000', $quantities['floor_area']['amount']);
        self::assertSame('estimated', $quantities['floor_area']['source']);
        self::assertSame([], $quantities['floor_area']['evidence_ids']);
        self::assertSame(['manual_project_model_correction'], $quantities['floor_area']['assumptions']);
        self::assertSame(['estimated_quantity_requires_review'], $quantities['floor_area']['review_blockers']);
        self::assertSame([
            'role' => 'area',
            'value' => '65.000000',
            'unit' => 'm2',
            'source' => 'estimated',
            'evidence_ids' => [],
            'assumptions' => ['manual_project_model_correction'],
            'context_id' => 'correction:room-1:area',
            'provenance_version' => 'project-model-correction:v1',
        ], $quantities['floor_area']['formula_inputs']['items'][0]['named_operands']['area']);
    }
}
