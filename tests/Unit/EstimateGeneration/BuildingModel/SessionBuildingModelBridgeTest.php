<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\InMemoryBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\SessionBuildingModelBridge;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\SessionBuildingModelUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionBuildingModelBridgeTest extends TestCase
{
    #[Test]
    public function mixed_vision_and_vector_units_produce_one_order_independent_persisted_model(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('f', 64));
        $units = [$this->visionUnit(), $this->vectorUnit()];

        [$first, $firstRepository] = $this->bridge();
        $forward = $first->store($context, $units);
        $retry = $first->store($context, array_reverse($units));

        [$second] = $this->bridge();
        $reverse = $second->store($context, array_reverse($units));

        self::assertNotNull($forward);
        self::assertSame($forward->toArray(), $retry?->toArray());
        self::assertSame($forward->toArray(), $reverse?->toArray());
        self::assertSame(2, $forward->metrics['evidence_count']);
        self::assertSame(2, $forward->metrics['room_count']);
        self::assertNotNull($firstRepository->currentModel($context));
        self::assertSame($forward->toArray(), $firstRepository->currentModel($context)?->toArray());
    }

    #[Test]
    public function latest_active_session_model_is_available_across_pipeline_input_versions(): void
    {
        [$bridge, $repository] = $this->bridge();
        $firstContext = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $latestContext = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('b', 64));
        $pipelineContext = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('c', 64));

        $bridge->store($firstContext, [$this->visionUnit()]);
        $latest = $bridge->store($latestContext, [$this->visionUnit(), $this->vectorUnit()]);

        self::assertNotNull($latest);
        self::assertNull($repository->currentModel($pipelineContext));
        self::assertSame($latest->toArray(), $repository->latestCurrentModel($pipelineContext)?->toArray());
    }

    #[Test]
    public function vision_cad_and_pdf_vector_pages_preserve_distinct_floor_identity_and_pdf_segments(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('e', 64));
        $vision = $this->visionUnit();
        $cad = $this->vectorUnit();
        $pdf = $this->pdfVectorUnit();

        [$forwardBridge] = $this->bridge();
        [$reverseBridge] = $this->bridge();
        $forward = $forwardBridge->store($context, [$vision, $cad, $pdf]);
        $reverse = $reverseBridge->store($context, [$pdf, $cad, $vision]);

        self::assertNotNull($forward);
        self::assertSame($forward->toArray(), $reverse?->toArray());
        self::assertSame(['floor-cad', 'floor-pdf-3', 'floor-vision'], array_column($forward->toArray()['floors'], 'key'));
        self::assertSame(1, $forward->metrics['wall_count']);
        self::assertSame(2, $forward->metrics['room_count']);
        $quantities = (new BuildingQuantityCalculator)->calculate(
            (new NormalizedBuildingModelQuantityInputMapper)->map($forward),
        );
        self::assertSame([], $quantities->all());
        self::assertNotEmpty(array_intersect(
            ['missing_wall_length', 'missing_wall_height', 'unconfirmed_scale'],
            array_column($quantities->diagnostics, 'code'),
        ));
    }

    #[Test]
    public function unanchored_vector_sources_do_not_create_extra_floors_when_recognized_floor_plans_exist(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('c', 64));
        $cad = $this->vectorUnit();
        $cadPayload = $cad->payload;
        unset($cadPayload['floor_key']);
        $cad = new SessionBuildingModelUnitData(
            $cad->unitId,
            $cad->documentId,
            $cad->pageId,
            $cad->type,
            $cad->index,
            $cad->sourceVersion,
            $cad->confidence,
            $cadPayload,
        );
        $pdf = $this->pdfVectorUnit();
        $pdfPayload = $pdf->payload;
        unset($pdfPayload['floor_key']);
        $pdf = new SessionBuildingModelUnitData(
            $pdf->unitId,
            $pdf->documentId,
            $pdf->pageId,
            $pdf->type,
            $pdf->index,
            $pdf->sourceVersion,
            $pdf->confidence,
            $pdfPayload,
        );

        [$bridge] = $this->bridge();
        $model = $bridge->store($context, [$this->visionUnit(), $cad, $pdf]);

        self::assertNotNull($model);
        self::assertSame(['floor-vision'], array_column($model->toArray()['floors'], 'key'));
        self::assertSame(1, $model->metrics['room_count']);
        self::assertSame(0, $model->metrics['wall_count']);
    }

    #[Test]
    public function unanchored_pdf_analysis_without_rooms_does_not_create_an_extra_floor(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('c', 64));
        $pdf = $this->pdfVectorUnit();
        $payload = $pdf->payload;
        unset($payload['floor_key']);
        $payload['vision_analysis'] = $this->visionUnit()->payload['vision_analysis'];
        $payload['vision_analysis']['elements'] = [];
        $payload['vision_analysis']['scale_candidates'] = [];
        $payload['vision_analysis']['warnings'] = ['scale_missing'];
        $pdf = new SessionBuildingModelUnitData(
            $pdf->unitId,
            $pdf->documentId,
            $pdf->pageId,
            $pdf->type,
            $pdf->index,
            $pdf->sourceVersion,
            $pdf->confidence,
            $payload,
        );

        [$bridge] = $this->bridge();
        $model = $bridge->store($context, [$this->visionUnit(), $pdf]);

        self::assertNotNull($model);
        self::assertSame(['floor-vision'], array_column($model->toArray()['floors'], 'key'));
        self::assertSame(1, $model->metrics['room_count']);
        self::assertSame(0, $model->metrics['wall_count']);
    }

    #[Test]
    public function unanchored_pdf_rooms_do_not_duplicate_primary_raster_floor_plans(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('c', 64));
        $pdf = $this->pdfVectorUnit();
        $payload = $pdf->payload;
        unset($payload['floor_key']);
        $payload['vision_analysis'] = $this->visionUnit()->payload['vision_analysis'];
        $pdf = new SessionBuildingModelUnitData(
            $pdf->unitId,
            $pdf->documentId,
            $pdf->pageId,
            $pdf->type,
            $pdf->index,
            $pdf->sourceVersion,
            $pdf->confidence,
            $payload,
        );

        [$bridge] = $this->bridge();
        $model = $bridge->store($context, [$this->visionUnit(), $pdf]);

        self::assertNotNull($model);
        self::assertSame(['floor-vision'], array_column($model->toArray()['floors'], 'key'));
        self::assertSame(1, $model->metrics['room_count']);
        self::assertSame(0, $model->metrics['wall_count']);
    }

    #[Test]
    public function malformed_production_pdf_geometry_wrapper_is_rejected_without_synthetic_bounds(): void
    {
        [$bridge] = $this->bridge();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pdf_page_geometry_contract_invalid');
        $bridge->store(
            new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('e', 64)),
            [new SessionBuildingModelUnitData(103, 503, 603, 'pdf_page', 3, 'sha256:'.str_repeat('c', 64), 1.0, [
                'pdf_geometry' => ['geometry' => ['page_number' => 3, 'width' => 0, 'height' => 700, 'rotation' => 0, 'vector_elements' => []]],
            ])],
        );
    }

    #[Test]
    public function first_pages_of_different_documents_keep_distinct_floor_identity(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('d', 64));
        [$bridge] = $this->bridge();

        $model = $bridge->store($context, [
            $this->unkeyedVisionUnit(101, 501, 601, 'a', 'first'),
            $this->unkeyedVisionUnit(102, 502, 602, 'b', 'second'),
        ]);

        self::assertNotNull($model);
        self::assertSame(2, $model->metrics['floor_count']);
        self::assertSame(['floor-document-501-page-1', 'floor-document-502-page-1'], array_column($model->toArray()['floors'], 'key'));
        $roomKeys = array_column(array_merge(...array_column($model->toArray()['floors'], 'rooms')), 'key');
        self::assertCount(2, array_unique($roomKeys));
    }

    #[Test]
    public function raster_floor_pages_with_different_pixel_scales_keep_metric_rooms_after_session_merge(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('d', 64));
        [$bridge] = $this->bridge();
        $first = $this->unkeyedVisionUnit(101, 501, 601, '1', 'first-scale');
        $firstPayload = $first->payload;
        $firstPayload['vision_analysis']['elements'][0]['polygon'] = [[0.0, 0.0], [1.0, 0.0], [1.0, 0.75], [0.0, 0.75]];
        $firstPayload['vision_analysis']['scale_candidates'][0]['meters_per_unit'] = 4.0;
        $firstScaleEvidence = $firstPayload['vision_analysis']['evidence'][0];
        $firstScaleEvidence['key'] = 'vision-scale-first';
        $firstPayload['vision_analysis']['evidence'][] = $firstScaleEvidence;
        $firstScaleCandidate = $firstPayload['vision_analysis']['scale_candidates'][0];
        $firstScaleCandidate['evidence_ref'] = 'vision-scale-first';
        $firstPayload['vision_analysis']['scale_candidates'][] = $firstScaleCandidate;
        $first = new SessionBuildingModelUnitData(
            $first->unitId, $first->documentId, $first->pageId, $first->type, $first->index,
            $first->sourceVersion, $first->confidence, $firstPayload,
        );
        $second = $this->unkeyedVisionUnit(102, 502, 602, '2', 'second-scale');
        $secondPayload = $second->payload;
        $secondPayload['vision_analysis']['elements'][0]['polygon'] = [[0.0, 0.0], [1.0, 0.0], [1.0, 0.8], [0.0, 0.8]];
        $secondPayload['vision_analysis']['scale_candidates'][0]['meters_per_unit'] = 5.0;
        $secondScaleEvidence = $secondPayload['vision_analysis']['evidence'][0];
        $secondScaleEvidence['key'] = 'vision-scale-second';
        $secondPayload['vision_analysis']['evidence'][] = $secondScaleEvidence;
        $secondScaleCandidate = $secondPayload['vision_analysis']['scale_candidates'][0];
        $secondScaleCandidate['evidence_ref'] = 'vision-scale-second';
        $secondPayload['vision_analysis']['scale_candidates'][] = $secondScaleCandidate;
        $second = new SessionBuildingModelUnitData(
            $second->unitId, $second->documentId, $second->pageId, $second->type, $second->index,
            $second->sourceVersion, $second->confidence, $secondPayload,
        );

        $model = $bridge->store($context, [$first, $second]);

        self::assertNotNull($model);
        self::assertSame('confirmed', $model->scaleStatus);
        self::assertSame(1.0, $model->scaleMetersPerUnit);
        self::assertSame(2, $model->metrics['floor_count']);
        self::assertSame(2, $model->metrics['room_count']);
        self::assertSame([[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]], $model->floors[0]->rooms[0]->polygon);
        self::assertSame([[0.0, 0.0], [5.0, 0.0], [5.0, 4.0], [0.0, 4.0]], $model->floors[1]->rooms[0]->polygon);
        self::assertSame(12.0, self::polygonArea($model->floors[0]->rooms[0]->polygon));
        self::assertSame(20.0, self::polygonArea($model->floors[1]->rooms[0]->polygon));
        self::assertSame([], $model->assumptions);
    }

    #[Test]
    public function elevations_remain_sources_but_do_not_create_building_floors(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('d', 64));
        [$bridge] = $this->bridge();
        $floor = $this->unkeyedVisionUnit(101, 501, 601, 'a', 'floor');
        $elevation = $this->unkeyedVisionUnit(102, 502, 602, 'b', 'elevation');
        $elevationPayload = $elevation->payload;
        $elevationPayload['vision_analysis']['sheet_type'] = 'elevation';
        $elevation = new SessionBuildingModelUnitData(
            $elevation->unitId,
            $elevation->documentId,
            $elevation->pageId,
            $elevation->type,
            $elevation->index,
            $elevation->sourceVersion,
            $elevation->confidence,
            $elevationPayload,
        );

        $model = $bridge->store($context, [$floor, $elevation]);

        self::assertNotNull($model);
        self::assertSame(1, $model->metrics['floor_count']);
        self::assertSame(['floor-document-501-page-1'], array_column($model->toArray()['floors'], 'key'));
    }

    #[Test]
    public function unknown_sheet_with_detected_rooms_remains_a_floor_source(): void
    {
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('d', 64));
        [$bridge] = $this->bridge();
        $unit = $this->unkeyedVisionUnit(101, 501, 601, 'a', 'unknown-room');
        $payload = $unit->payload;
        $payload['vision_analysis']['sheet_type'] = 'unknown';

        $model = $bridge->store($context, [new SessionBuildingModelUnitData(
            $unit->unitId,
            $unit->documentId,
            $unit->pageId,
            $unit->type,
            $unit->index,
            $unit->sourceVersion,
            $unit->confidence,
            $payload,
        )]);

        self::assertNotNull($model);
        self::assertSame(1, $model->metrics['floor_count']);
        self::assertSame(1, $model->metrics['room_count']);
    }

    /** @return array{SessionBuildingModelBridge, BuildingModelRepository} */
    private function bridge(): array
    {
        $evidence = new InMemoryEvidenceRepository;
        $repository = new BuildingModelRepository(new InMemoryBuildingModelStore, $evidence);

        return [new SessionBuildingModelBridge(
            $evidence,
            new GeometryBuildingModelInputMapper,
            new BuildingModelAssembler,
            $repository,
        ), $repository];
    }

    private function visionUnit(): SessionBuildingModelUnitData
    {
        $source = 'sha256:'.str_repeat('a', 64);

        return new SessionBuildingModelUnitData(101, 501, 601, 'sketch', 1, $source, 0.95, [
            'source_kind' => 'sketch',
            'floor_key' => 'floor-vision',
            'vision_analysis' => [
                'schema_version' => 1,
                'sheet_type' => 'floor_plan',
                'evidence' => [['key' => 'vision-page', 'locator' => [
                    'page_id' => 601, 'page_number' => 1, 'processing_unit_id' => 101,
                    'source_version' => $source, 'coordinate_space' => 'normalized_source_v1',
                ]]],
                'elements' => [[
                    'key' => 'vision-room', 'type' => 'room', 'label' => null,
                    'polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]],
                    'confidence' => 0.95, 'evidence_ref' => 'vision-page',
                ]],
                'scale_candidates' => [[
                    'source' => 'manual_reference', 'meters_per_unit' => 0.001,
                    'confidence' => 1.0, 'evidence_ref' => 'vision-page', 'detail' => 'confirmed_control_dimension',
                ]],
                'warnings' => [], 'provider' => 'timeweb', 'requested_model' => 'vision/model',
                'reported_model' => 'vision/model', 'model_version' => 'provider:v1',
                'usage' => ['status' => 'unavailable', 'input_tokens' => null, 'output_tokens' => null],
            ],
        ]);
    }

    private function vectorUnit(): SessionBuildingModelUnitData
    {
        $source = 'sha256:'.str_repeat('b', 64);

        return new SessionBuildingModelUnitData(102, 502, 602, 'cad_drawing', 1, $source, 1.0, [
            'source_kind' => 'cad',
            'floor_key' => 'floor-cad',
            'vector_geometry' => [
                'schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
                'source_fingerprint' => $source, 'source_unit' => 'mm', 'unit_status' => 'confirmed',
                'bounds' => [0, 0, 4000, 3000], 'layers' => [['name' => 'ROOMS', 'visible' => true]],
                'blocks' => [], 'entities' => [[
                    'handle' => 'A1', 'type' => 'lwpolyline', 'layer' => 'ROOMS',
                    'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true,
                ]],
                'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => [],
            ],
        ]);
    }

    private function pdfVectorUnit(): SessionBuildingModelUnitData
    {
        $source = 'sha256:'.str_repeat('c', 64);

        return new SessionBuildingModelUnitData(103, 503, 603, 'pdf_page', 3, $source, 1.0, [
            'source_kind' => 'pdf_page',
            'floor_key' => 'floor-pdf-3',
            'pdf_geometry' => [
                'schema_version' => 1,
                'geometry' => [
                    'page_number' => 3,
                    'width' => 1000,
                    'height' => 700,
                    'rotation' => 0,
                    'vector_elements' => [[
                        'kind' => 'line',
                        'geometry' => ['points' => [[0, 0], [500, 0]]],
                        'style' => ['source_operator' => 'page:3:object:7'],
                    ]],
                    'text_blocks' => [],
                    'visual_metrics' => [],
                    'page_role' => 'geometry_only',
                    'signals' => ['vector_geometry'],
                ],
            ],
        ]);
    }

    private function unkeyedVisionUnit(int $unitId, int $documentId, int $pageId, string $hashCharacter, string $suffix): SessionBuildingModelUnitData
    {
        $source = 'sha256:'.str_repeat($hashCharacter, 64);
        $payload = $this->visionUnit()->payload;
        unset($payload['floor_key']);
        $payload['vision_analysis']['evidence'][0]['key'] = 'vision-page-'.$suffix;
        $payload['vision_analysis']['evidence'][0]['locator']['page_id'] = $pageId;
        $payload['vision_analysis']['evidence'][0]['locator']['processing_unit_id'] = $unitId;
        $payload['vision_analysis']['evidence'][0]['locator']['source_version'] = $source;
        $payload['vision_analysis']['elements'][0]['key'] = 'vision-room';
        $payload['vision_analysis']['elements'][0]['evidence_ref'] = 'vision-page-'.$suffix;
        $payload['vision_analysis']['scale_candidates'][0]['evidence_ref'] = 'vision-page-'.$suffix;

        return new SessionBuildingModelUnitData($unitId, $documentId, $pageId, 'sketch', 1, $source, 0.95, $payload);
    }

    private static function polygonArea(array $polygon): float
    {
        $area = 0.0;
        foreach ($polygon as $index => $point) {
            $next = $polygon[($index + 1) % count($polygon)];
            $area += $point[0] * $next[1] - $next[0] * $point[1];
        }

        return abs($area) / 2;
    }
}
