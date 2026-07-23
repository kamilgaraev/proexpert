<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\AssumptionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoomAnnotationFloorAreaQuantityFactoryTest extends TestCase
{
    #[Test]
    public function accepts_complete_room_annotations_at_the_document_quality_threshold(): void
    {
        self::assertSame('42.700000', $this->quantityForConfidence(0.70)?->amount);
        self::assertNull($this->quantityForConfidence(0.69));
    }

    #[Test]
    public function keeps_document_room_areas_after_user_confirms_the_drawing_scale(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:'.hash('sha256', 'room')],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v2',
        ));
        $confirmation = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::SourceFact, EvidenceSourceType::UserInput, 'input:17',
            'sha256:'.str_repeat('c', 64), ['source_key' => 'source:'.str_repeat('d', 64)],
            ['fact_key' => 'element_type_code', 'fact_value' => 'element_type:room'],
            1, 'user_input_normalizer', 'model:v1',
        ));
        $room = new RoomData('room-1', 'Kitchen 42,7', null, [$area->id], 0.95, 'confirmed');
        $model = new NormalizedBuildingModelData('m', 'confirmed', 0.019244, [new FloorData(
            'floor-1', null, null, [$room], [], [], [], [$area->id, $confirmation->id], 0.95, 'confirmed',
        )], [], 'building-model:v1');

        $quantity = (new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model, 1);

        self::assertSame('42.700000', $quantity?->amount);
        self::assertSame([(string) $area->id], $quantity?->evidenceIds);
    }

    #[Test]
    public function sums_only_document_backed_internal_room_annotations_without_scale(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $rooms = [];
        foreach ([
            ['СУ 4,9', 4.9],
            ['Топочная 4,8', 4.8],
            ['Гостиная 21,9', 21.9],
            ['Тренажерный зал 27,7', 27.7],
            ['Холл 8,8', 8.8],
            ['Кухня-столовая 42,7', 42.7],
            ['Тамбур 2,5', 2.5],
            ['Веранда 20,8', 20.8],
            ['СУ 6,9', 6.9],
            ['Спальня 17,4', 17.4],
            ['Спальня 25,3', 25.3],
            ['Холл 6,7', 6.7],
            ['Кладовая 2,8', 2.8],
            ['Кабинет 16,5', 16.5],
            ['Тамбур 3,9', 3.9],
        ] as $index => [$name, $area]) {
            $node = $evidence->insertOrGet(new EvidenceData(
                10,
                20,
                30,
                EvidenceType::Extracted,
                EvidenceSourceType::DocumentUnit,
                'document:'.($index < 8 ? 501 : 502),
                'sha256:'.str_repeat($index < 8 ? 'b' : 'c', 64),
                ['document_id' => $index < 8 ? 501 : 502, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:'.hash('sha256', (string) $index)],
                ['field_key' => 'room_area', 'field_value' => $area, 'unit' => 'm2'],
                0.95,
                EvidenceProducer::DrawingAnalyzer->value,
                'model:v2',
            ));
            $rooms[] = new RoomData('room-'.$index, $name, null, [$node->id], 0.95, 'unknown');
        }
        $firstFloorRooms = array_slice($rooms, 0, 8);
        $secondFloorRooms = array_slice($rooms, 8);
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [
            new FloorData('floor-1', null, null, $firstFloorRooms, [], [], [], array_merge(...array_column($firstFloorRooms, 'evidenceIds')), 0.95, 'unknown'),
            new FloorData('floor-2', null, null, $secondFloorRooms, [], [], [], array_merge(...array_column($secondFloorRooms, 'evidenceIds')), 0.95, 'unknown'),
        ], [new AssumptionData('scale_missing', 'blocking', ['floor-1', 'floor-2'], [1], true)], 'building-model:v1');

        $factory = new RoomAnnotationFloorAreaQuantityFactory($evidence);
        $quantity = $factory->make($context, $model, 2);

        self::assertNotNull($quantity);
        self::assertSame('192.800000', $quantity->amount);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame([], $quantity->reviewBlockers);
        self::assertCount(14, $quantity->formulaInputs['items']);
        self::assertCount(14, $quantity->evidenceIds);
        self::assertNull($factory->make($context, $model, 3));
    }

    #[Test]
    public function does_not_trust_a_numeric_room_label_without_room_area_extraction(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $node = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64), ['document_id' => 501],
            ['field_key' => 'element_type_code', 'field_value' => 'element_type:room'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v1',
        ));
        $room = new RoomData('room-1', 'Кухня 42,7', null, [$node->id], 0.95, 'unknown');
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData(
            'floor-1', null, null, [$room], [], [], [], [$node->id], 0.95, 'unknown',
        )], [new AssumptionData('scale_missing', 'blocking', ['floor-1'], [$node->id], true)], 'building-model:v1');

        self::assertNull((new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model));
    }

    #[Test]
    public function rejects_an_incomplete_selected_floor_even_when_only_external_room_evidence_is_missing(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:'.hash('sha256', 'kitchen')],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v2',
        ));
        $generic = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64), ['document_id' => 501],
            ['field_key' => 'element_type_code', 'field_value' => 'element_type:room'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v1',
        ));
        $rooms = [
            new RoomData('room-1', 'Кухня 42,7', null, [$area->id], 0.95, 'unknown'),
            new RoomData('room-2', 'Веранда 20,8', null, [$generic->id], 0.95, 'unknown'),
        ];
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData(
            'floor-1', null, null, $rooms, [], [], [], [$area->id, $generic->id], 0.95, 'unknown',
        )], [new AssumptionData('scale_missing', 'blocking', ['floor-1'], [$generic->id], true)], 'building-model:v1');

        self::assertNull((new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model));
    }

    #[Test]
    public function rejects_a_partial_total_when_another_raster_floor_has_no_area_facts(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:'.hash('sha256', 'floor-1-room')],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v2',
        ));
        $secondFloor = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:502',
            'sha256:'.str_repeat('c', 64),
            ['document_id' => 502, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1],
            ['field_key' => 'element_type_code', 'field_value' => 'element_type:floor'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v1',
        ));
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [
            new FloorData('floor-1', null, null, [
                new RoomData('room-1', 'Кухня 42,7', null, [$area->id], 0.95, 'unknown'),
            ], [], [], [], [$area->id], 0.95, 'unknown'),
            new FloorData('floor-2', null, null, [
                new RoomData('room-2', 'Спальня', null, [$secondFloor->id], 0.95, 'unknown'),
            ], [], [], [], [$secondFloor->id], 0.95, 'unknown'),
        ], [new AssumptionData('scale_missing', 'blocking', ['floor-1', 'floor-2'], [$area->id], true)], 'building-model:v1');

        self::assertNull((new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model));
    }

    #[Test]
    public function ignores_an_explicit_empty_reference_floor_with_pdf_provenance(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1, 'element_key' => 'element:'.hash('sha256', 'room')],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95, EvidenceProducer::DrawingAnalyzer->value, 'model:v2',
        ));
        $pdf = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:503',
            'sha256:'.str_repeat('c', 64),
            ['document_id' => 503, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'page' => 1],
            ['field_key' => 'element_type_code', 'field_value' => 'element_type:floor'],
            0.95, EvidenceProducer::PdfGeometry->value, 'extractor:v1',
        ));
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [
            new FloorData('floor-1', null, null, [
                new RoomData('room-1', 'Кухня 42,7', null, [$area->id], 0.95, 'unknown'),
            ], [], [], [], [$area->id], 0.95, 'unknown'),
            new FloorData('floor-pdf', null, null, [], [], [], [], [$pdf->id], 0.95, 'unknown'),
        ], [new AssumptionData('scale_missing', 'blocking', ['floor-1', 'floor-pdf'], [$area->id], true)], 'building-model:v1');

        $quantity = (new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model, 1);

        self::assertSame('42.700000', $quantity?->amount);
    }

    #[Test]
    public function exposes_separate_evidence_backed_areas_for_a_two_storey_house(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $floors = [];
        foreach ([
            'floor-1' => [['Kitchen 70,0', 70.0], ['Hall 43,3', 43.3]],
            'floor-2' => [['Bedroom 50,0', 50.0], ['Study 29,5', 29.5]],
        ] as $floorKey => $roomDefinitions) {
            $rooms = [];
            foreach ($roomDefinitions as $roomIndex => [$name, $area]) {
                $node = $evidence->insertOrGet(new EvidenceData(
                    10,
                    20,
                    30,
                    EvidenceType::Extracted,
                    EvidenceSourceType::DocumentUnit,
                    'document:'.($floorKey === 'floor-1' ? '501' : '502'),
                    'sha256:'.hash('sha256', $floorKey),
                    [
                        'document_id' => $floorKey === 'floor-1' ? 501 : 502,
                        'unit_type' => 'raster_image',
                        'unit_index' => 1,
                        'page' => 1,
                        'element_key' => 'element:'.hash('sha256', $floorKey.'-room-'.$roomIndex),
                    ],
                    ['field_key' => 'room_area', 'field_value' => $area, 'unit' => 'm2'],
                    0.95,
                    EvidenceProducer::DrawingAnalyzer->value,
                    'model:v2',
                ));
                $rooms[] = new RoomData($floorKey.'-room-'.$roomIndex, $name, null, [$node->id], 0.95, 'unknown');
            }
            $floors[] = new FloorData(
                $floorKey,
                null,
                null,
                $rooms,
                [],
                [],
                [],
                array_merge(...array_column($rooms, 'evidenceIds')),
                0.95,
                'unknown',
            );
        }
        $model = new NormalizedBuildingModelData(
            'm',
            'unknown',
            null,
            $floors,
            [new AssumptionData('scale_missing', 'blocking', ['floor-1', 'floor-2'], [1], true)],
            'building-model:v1',
        );

        $quantities = (new RoomAnnotationFloorAreaQuantityFactory($evidence))->makeAll($context, $model, 2);

        self::assertSame('192.800000', $quantities['floor_area']->amount);
        self::assertSame('192.800000', $quantities['ceiling_area']->amount);
        self::assertSame('document.rooms.internal_ceiling_area_sum', $quantities['ceiling_area']->formulaKey);
        self::assertSame('113.300000', $quantities['first_floor_internal_area']->amount);
        self::assertSame('79.500000', $quantities['upper_floor_internal_area']->amount);
        self::assertCount(2, $quantities['first_floor_internal_area']->evidenceIds);
        self::assertCount(2, $quantities['upper_floor_internal_area']->evidenceIds);
    }

    #[Test]
    public function does_not_expose_floor_specific_areas_when_floor_role_is_not_identifiable(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10,
            20,
            30,
            EvidenceType::Extracted,
            EvidenceSourceType::DocumentUnit,
            'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            0.95,
            EvidenceProducer::DrawingAnalyzer->value,
            'model:v2',
        ));
        $room = new RoomData('room-1', 'Kitchen 42,7', null, [$area->id], 0.95, 'unknown');
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData(
            'level-a', null, null, [$room], [], [], [], [$area->id], 0.95, 'unknown',
        )], [new AssumptionData('scale_missing', 'blocking', ['level-a'], [$area->id], true)], 'building-model:v1');

        $quantities = (new RoomAnnotationFloorAreaQuantityFactory($evidence))->makeAll($context, $model, 1);

        self::assertSame('42.700000', $quantities['floor_area']->amount);
        self::assertArrayNotHasKey('first_floor_internal_area', $quantities);
        self::assertArrayNotHasKey('upper_floor_internal_area', $quantities);
    }

    private function quantityForConfidence(float $confidence): ?\App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new BuildingModelOperationContext(10, 20, 30, 'sha256:'.str_repeat('a', 64));
        $area = $evidence->insertOrGet(new EvidenceData(
            10, 20, 30, EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:501',
            'sha256:'.str_repeat('b', 64),
            ['document_id' => 501, 'unit_type' => 'raster_image', 'unit_index' => 1, 'page' => 1],
            ['field_key' => 'room_area', 'field_value' => 42.7, 'unit' => 'm2'],
            $confidence, EvidenceProducer::DrawingAnalyzer->value, 'model:v2',
        ));
        $model = new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData(
            'floor-1', null, null, [
                new RoomData('room-1', 'Кухня 42,7', null, [$area->id], $confidence, 'unknown'),
            ], [], [], [], [$area->id], $confidence, 'unknown',
        )], [new AssumptionData('scale_missing', 'blocking', ['floor-1'], [$area->id], true)], 'building-model:v1');

        return (new RoomAnnotationFloorAreaQuantityFactory($evidence))->make($context, $model, 1);
    }
}
