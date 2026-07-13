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
        ]);
    }
}
