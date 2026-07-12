<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use PHPUnit\Framework\TestCase;

final class GeometryBuildingModelInputMapperTest extends TestCase
{
    public function test_vision_room_preserves_exact_provenance_and_resolves_scale(): void
    {
        $fingerprint = 'sha256:'.str_repeat('a', 64);
        $vision = VisionAnalysisData::fromProviderArray([
            'schema_version' => 1,
            'sheet_type' => 'floor_plan',
            'evidence' => [
                ['key' => 'page-1', 'locator' => ['page_id' => 10, 'page_number' => 2, 'processing_unit_id' => 20, 'source_version' => $fingerprint, 'coordinate_space' => 'normalized_source_v1']],
                ['key' => 'page-1-scale', 'locator' => ['page_id' => 10, 'page_number' => 2, 'processing_unit_id' => 20, 'source_version' => $fingerprint, 'coordinate_space' => 'normalized_source_v1']],
            ],
            'elements' => [['key' => 'room-1', 'type' => 'room', 'label' => 'Кухня', 'polygon' => [[0.1, 0.1], [0.5, 0.1], [0.5, 0.4], [0.1, 0.4]], 'confidence' => 0.93, 'evidence_ref' => 'page-1']],
            'scale_candidates' => [
                ['source' => 'dimension_text', 'meters_per_unit' => 10.0, 'confidence' => 0.91, 'evidence_ref' => 'page-1', 'detail' => 'visible_dimension'],
                ['source' => 'scale_notation', 'meters_per_unit' => 10.0, 'confidence' => 0.88, 'evidence_ref' => 'page-1-scale', 'detail' => 'drawing_scale'],
            ],
            'warnings' => [],
        ], 'timeweb', 'vision/model', 'vision/model', 'provider:v1', 'unavailable', null, null, 50);

        $input = (new GeometryBuildingModelInputMapper)->map($vision, null, ['page-1' => 101, 'page-1-scale' => 102], 'floor-2');

        self::assertSame('confirmed', $input->scale->status);
        self::assertSame('room', $input->geometry->elements[0]->type);
        self::assertSame([
            'evidence_ref' => 'page-1', 'source_type' => 'vision', 'source_fingerprint' => $fingerprint,
            'page_number' => 2, 'coordinate_space' => 'normalized_source_v1',
            'coordinate_transform' => 'normalized_source_v1', 'runtime_version' => 'vision-contract:v1',
            'model_version' => 'provider:v1', 'confidence' => 0.93,
        ], $input->geometry->elements[0]->provenance[0]);
    }

    public function test_vector_closed_polyline_uses_source_units_and_confirmed_unit_scale(): void
    {
        $fingerprint = 'sha256:'.str_repeat('b', 64);
        $vector = VectorGeometryData::fromArray([
            'schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4', 'source_fingerprint' => $fingerprint,
            'source_unit' => 'mm', 'unit_status' => 'confirmed', 'bounds' => [0, 0, 4000, 3000],
            'layers' => [['name' => 'ROOMS', 'visible' => true]], 'blocks' => [],
            'entities' => [['handle' => 'A1', 'type' => 'lwpolyline', 'layer' => 'ROOMS', 'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true]],
            'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => [],
        ]);

        $input = (new GeometryBuildingModelInputMapper)->map(null, $vector, ['vector:A1' => 202]);

        self::assertSame('0.001', (string) $input->scale->metersPerUnit);
        self::assertSame('room', $input->geometry->elements[0]->type);
        self::assertSame('source_units_v1', $input->geometry->elements[0]->coordinateSpace);
        self::assertSame($fingerprint, $input->geometry->elements[0]->sourceFingerprint);
    }

    public function test_sketch_without_scale_is_fail_closed_and_requests_review(): void
    {
        $fingerprint = 'sha256:'.str_repeat('c', 64);
        $vision = VisionAnalysisData::fromProviderArray([
            'schema_version' => 1, 'sheet_type' => 'sketch',
            'evidence' => [['key' => 'sketch-1', 'locator' => ['page_id' => 1, 'page_number' => 1, 'processing_unit_id' => 2, 'source_version' => $fingerprint, 'coordinate_space' => 'normalized_source_v1']]],
            'elements' => [['key' => 'room-1', 'type' => 'room', 'label' => null, 'polygon' => [[0.1, 0.1], [0.8, 0.1], [0.8, 0.8]], 'confidence' => 0.7, 'evidence_ref' => 'sketch-1']],
            'scale_candidates' => [], 'warnings' => ['scale_missing'],
        ], 'timeweb', 'vision/model', 'vision/model', 'provider:v1', 'unavailable', null, null, 50);

        $input = (new GeometryBuildingModelInputMapper)->map($vision, null, ['sketch-1' => 303]);
        $result = (new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler)->assembleVision($input);

        self::assertSame('missing', $input->scale->status);
        self::assertSame('unknown', $result->model->scaleStatus);
        self::assertNull($result->model->floors[0]->rooms[0]->polygon);
        self::assertContains('scale_missing', array_map(static fn ($item): string => $item->code, $result->model->assumptions));
    }

    public function test_vision_opening_requires_closed_geometry_metadata_and_preserves_provenance(): void
    {
        $fingerprint = 'sha256:'.str_repeat('d', 64);
        $vision = VisionAnalysisData::fromProviderArray([
            'schema_version' => 1, 'sheet_type' => 'floor_plan',
            'evidence' => [
                ['key' => 'wall-evidence', 'locator' => ['page_id' => 1, 'page_number' => 1, 'processing_unit_id' => 2, 'source_version' => $fingerprint, 'coordinate_space' => 'normalized_source_v1']],
                ['key' => 'opening-evidence', 'locator' => ['page_id' => 1, 'page_number' => 1, 'processing_unit_id' => 2, 'source_version' => $fingerprint, 'coordinate_space' => 'normalized_source_v1']],
            ],
            'elements' => [
                ['key' => 'wall-1', 'type' => 'wall', 'label' => null, 'polygon' => [[0.0, 0.0], [1.0, 0.0]], 'confidence' => 0.95, 'evidence_ref' => 'wall-evidence'],
                ['key' => 'opening-1', 'type' => 'opening', 'label' => 'door', 'polygon' => [[0.2, 0.0], [0.4, 0.0]], 'confidence' => 0.94, 'evidence_ref' => 'opening-evidence',
                    'geometry' => ['wall_key' => 'wall-1', 'opening_type' => 'door', 'offset' => 0.2, 'width' => 0.2, 'height' => 0.21]],
            ],
            'scale_candidates' => [['source' => 'manual_reference', 'meters_per_unit' => 10.0, 'confidence' => 1.0, 'evidence_ref' => 'wall-evidence', 'detail' => 'confirmed_control_dimension']], 'warnings' => [],
        ], 'timeweb', 'vision/model', 'vision/model', 'provider:v1', 'unavailable', null, null, 50);

        $result = (new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler)->assembleVision(
            (new GeometryBuildingModelInputMapper)->map($vision, null, ['wall-evidence' => 403, 'opening-evidence' => 404]),
        );

        self::assertSame('wall-1', $result->model->floors[0]->openings[0]->wallKey);
        self::assertSame([404], $result->model->floors[0]->openings[0]->evidenceIds);
    }

    public function test_vector_opening_requires_explicit_semantics(): void
    {
        $vector = VectorGeometryData::fromArray([
            'schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4', 'source_fingerprint' => 'sha256:'.str_repeat('e', 64),
            'source_unit' => 'mm', 'unit_status' => 'confirmed', 'bounds' => [0, 0, 4000, 3000],
            'layers' => [['name' => 'A-WALL', 'visible' => true]], 'blocks' => [],
            'entities' => [
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A-WALL', 'points' => [[0, 0], [4000, 0]]],
                ['handle' => 'O1', 'type' => 'line', 'layer' => 'A-WALL', 'points' => [[1000, 0], [1900, 0]],
                    'semantic' => ['kind' => 'opening', 'wall_handle' => 'W1', 'opening_type' => 'door', 'offset' => 1000, 'width' => 900, 'height' => 2100]],
            ],
            'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => [],
        ]);

        $result = (new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler)->assembleVision(
            (new GeometryBuildingModelInputMapper)->map(null, $vector, ['vector:W1' => 501, 'vector:O1' => 502]),
        );

        self::assertSame('vector-w1', $result->model->floors[0]->openings[0]->wallKey);
        self::assertSame([502], $result->model->floors[0]->openings[0]->evidenceIds);
        self::assertTrue($result->model->metrics['complete']);
    }
}
