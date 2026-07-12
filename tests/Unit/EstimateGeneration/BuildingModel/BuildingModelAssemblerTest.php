<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelDetectionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\VisionBuildingModelInputData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\FusedGeometryElementData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryFusionService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolutionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchAssumption;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchProvenanceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchQuestionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchValueData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildingModelAssemblerTest extends TestCase
{
    #[Test]
    public function merge_is_deterministic_and_conflicts_become_blocking_assumptions(): void
    {
        $a = new BuildingModelDetectionData('vector-pdf:v1', 'confirmed', 0.01, [self::floor('room-1', 4)], [11]);
        $b = new BuildingModelDetectionData('vision:v1', 'confirmed', 0.01, [self::floor('room-1', 5)], [12]);
        $assembler = new BuildingModelAssembler;

        $first = $assembler->assemble([$a, $b]);
        $second = $assembler->assemble([$b, $a]);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('geometry_conflict', $first->assumptions[0]->code);
        self::assertSame('blocking', $first->assumptions[0]->severity);
        self::assertSame([11, 12], $first->assumptions[0]->evidenceIds);
    }

    #[Test]
    public function missing_scale_never_invents_metric_geometry(): void
    {
        $floor = new FloorData('floor-1', null, null, [new RoomData('room-1', null, null, [11], 0.8, 'unknown')], [], [], [], [11], 0.8, 'unknown');
        $model = (new BuildingModelAssembler)->assemble([
            new BuildingModelDetectionData('sketch:v1', 'unknown', null, [$floor], [11]),
        ]);

        self::assertSame('unknown', $model->scaleStatus);
        self::assertNull($model->floors[0]->rooms[0]->polygon);
    }

    #[Test]
    public function conflicting_scale_is_a_typed_blocker_and_drops_all_metric_geometry(): void
    {
        $confirmed = new BuildingModelDetectionData('vector-pdf:v1', 'confirmed', 0.01, [self::floor('room-1', 4)], [11]);
        $estimatedFloor = new FloorData('floor-2', 3, 2.8, [], [], [], [], [12], 0.8, 'estimated');
        $estimated = new BuildingModelDetectionData('vision:v1', 'estimated', 0.02, [$estimatedFloor], [12]);

        $model = (new BuildingModelAssembler)->assemble([$confirmed, $estimated]);

        self::assertSame('unknown', $model->scaleStatus);
        self::assertNull($model->scaleMetersPerUnit);
        self::assertSame('scale_conflict', $model->assumptions[0]->code);
        self::assertNull($model->floors[0]->rooms[0]->polygon);
        self::assertNull($model->floors[1]->elevationM);
    }

    #[Test]
    public function disjoint_elements_are_merged_without_conflict(): void
    {
        $first = new BuildingModelDetectionData('vector-pdf:v1', 'confirmed', 0.01, [self::floor('room-1', 4)], [11]);
        $second = new BuildingModelDetectionData('vision:v1', 'confirmed', 0.01, [self::floor('room-2', 5)], [12]);

        $model = (new BuildingModelAssembler)->assemble([$second, $first]);

        self::assertSame(['room-1', 'room-2'], array_map(static fn (RoomData $room): string => $room->key, $model->floors[0]->rooms));
        self::assertSame([], $model->assumptions);
    }

    #[Test]
    public function estimated_and_unknown_single_detections_always_create_auto_apply_blockers(): void
    {
        $estimatedFloor = new FloorData('floor-1', 0, 2.8, [], [], [], [], [11], 0.8, 'estimated');
        $estimated = (new BuildingModelAssembler)->assemble([
            new BuildingModelDetectionData('vision:v1', 'estimated', 0.02, [$estimatedFloor], [11]),
        ]);
        $unknownFloor = new FloorData('floor-1', null, null, [], [], [], [], [12], 0.8, 'unknown');
        $unknown = (new BuildingModelAssembler)->assemble([
            new BuildingModelDetectionData('sketch:v1', 'unknown', null, [$unknownFloor], [12]),
        ]);

        self::assertSame('scale_estimated', $estimated->assumptions[0]->code);
        self::assertSame('blocking', $estimated->assumptions[0]->severity);
        self::assertTrue($estimated->assumptions[0]->requiresConfirmation);
        self::assertSame('scale_missing', $unknown->assumptions[0]->code);
        self::assertSame('blocking', $unknown->assumptions[0]->severity);
        self::assertTrue($unknown->assumptions[0]->requiresConfirmation);
    }

    #[Test]
    public function vision_input_builds_deterministic_confirmed_model_with_evidence(): void
    {
        $geometry = (new GeometryFusionService)->fuse([
            self::sourceRoom('room-2', 'e2', [[0.0, 0.0], [200.0, 0.0], [200.0, 100.0], [0.0, 100.0]]),
            self::sourceRoom('room-1', 'e1', [[0.0, 0.0], [100.0, 0.0], [100.0, 100.0], [0.0, 100.0]]),
        ]);
        $input = new VisionBuildingModelInputData(
            new ScaleResolutionData('confirmed', 0.01, ['e1', 'e2'], null),
            $geometry,
            [self::userAssumption('floor_count', 1, 'e1')],
            [],
            ['e1' => 11, 'e2' => 12],
            'vision-fusion:v1',
            'floor-1',
        );

        $first = (new BuildingModelAssembler)->assembleVision($input);
        $second = (new BuildingModelAssembler)->assembleVision($input);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('confirmed', $first->model->scaleStatus);
        self::assertSame(0.01, $first->model->scaleMetersPerUnit);
        self::assertSame([11, 12], $first->model->evidenceIds);
        self::assertSame([[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]], $first->model->floors[0]->rooms[0]->polygon);
    }

    #[Test]
    public function missing_scale_preserves_source_geometry_questions_and_assumptions_without_metric_data(): void
    {
        $geometry = (new GeometryFusionService)->fuse([self::sourceRoom('room-1', 'e1', [[0.0, 0.0], [100.0, 0.0], [100.0, 100.0], [0.0, 100.0]])]);
        $catalog = new SketchAssumption(new SketchValueData('roof_type', 'gable'), new SketchProvenanceData('catalog_default', null, null, 'sha256:'.str_repeat('c', 64), 1, 'catalog:v1'), 0.8, false);
        $result = (new BuildingModelAssembler)->assembleVision(new VisionBuildingModelInputData(
            new ScaleResolutionData('missing', null, [], 'geometry_scale_unconfirmed'),
            $geometry,
            [$catalog],
            [new SketchQuestionData('floor_height')],
            ['e1' => 11],
            'vision-fusion:v1',
            'floor-1',
        ));

        self::assertSame('unknown', $result->model->scaleStatus);
        self::assertNull($result->model->floors[0]->rooms[0]->polygon);
        self::assertSame([], array_filter($result->model->metrics, static fn (mixed $value, string $key): bool => str_contains($key, 'area') || str_contains($key, 'quantity'), ARRAY_FILTER_USE_BOTH));
        self::assertSame($geometry->toArray()['elements'], $result->sourceGeometry);
        self::assertSame([['key' => 'floor_height']], array_map(static fn (SketchQuestionData $item): array => $item->toArray(), $result->questions));
        self::assertSame('geometry_scale_unconfirmed', $result->clarifications[0]->code);
        self::assertTrue($result->sketchAssumptions[0]->requiresConfirmation);
        self::assertFalse($result->sketchAssumptions[0]->evidenced);
    }

    #[Test]
    public function scale_and_geometry_conflicts_propagate_all_evidence_independently_of_order(): void
    {
        $a = self::sourceRoom('room-1', 'e1', [[0.0, 0.0], [100.0, 0.0], [100.0, 100.0], [0.0, 100.0]]);
        $b = self::sourceRoom('room-1', 'e2', [[0.0, 0.0], [200.0, 0.0], [200.0, 100.0], [0.0, 100.0]]);
        $service = new GeometryFusionService;
        $scale = new ScaleResolutionData('conflict', null, ['e3', 'e1'], 'geometry_scale_conflict');
        $make = static fn (array $items): VisionBuildingModelInputData => new VisionBuildingModelInputData(
            $scale,
            $service->fuse($items),
            [],
            [],
            ['e1' => 11, 'e2' => 12, 'e3' => 13],
            'vision-fusion:v1',
            'floor-1',
        );

        $first = (new BuildingModelAssembler)->assembleVision($make([$a, $b]));
        $second = (new BuildingModelAssembler)->assembleVision($make([$b, $a]));

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('unknown', $first->model->scaleStatus);
        self::assertContains('geometry_scale_conflict', array_map(static fn ($item): string => $item->code, $first->clarifications));
        self::assertContains('geometry_element_conflict', array_map(static fn ($item): string => $item->code, $first->clarifications));
        self::assertSame([], $first->model->floors[0]->rooms);
        self::assertCount(2, $first->sourceGeometry);
    }

    #[Test]
    public function all_geometry_types_are_assembled_or_blocked_when_references_are_insufficient(): void
    {
        $elements = [
            self::sourceRoom('room-1', 'e1', [[0.0, 0.0], [100.0, 0.0], [100.0, 100.0], [0.0, 100.0]]),
            self::sourceElement('wall-1', 'wall', 'e2', ['start' => [0.0, 0.0], 'end' => [100.0, 0.0], 'thickness' => 10.0, 'height' => 280.0]),
            self::sourceElement('opening-1', 'opening', 'e3', ['wall_key' => 'wall-1', 'opening_type' => 'door', 'offset' => 10.0, 'width' => 20.0, 'height' => 200.0]),
            self::sourceElement('engineering-1', 'engineering_element', 'e4', ['engineering_type' => 'outlet', 'location' => [10.0, 10.0], 'room_key' => 'room-1']),
            self::sourceElement('opening-orphan', 'opening', 'e5', ['wall_key' => 'wall-missing', 'opening_type' => 'door', 'offset' => 1.0, 'width' => 2.0, 'height' => 2.0]),
        ];
        $result = (new BuildingModelAssembler)->assembleVision(new VisionBuildingModelInputData(
            new ScaleResolutionData('confirmed', 0.01, ['e1'], null), (new GeometryFusionService)->fuse($elements), [], [],
            ['e1' => 11, 'e2' => 12, 'e3' => 13, 'e4' => 14, 'e5' => 15], 'vision-fusion:v1', 'floor-1',
        ));

        self::assertCount(1, $result->model->floors[0]->rooms);
        self::assertCount(1, $result->model->floors[0]->walls);
        self::assertCount(1, $result->model->floors[0]->openings);
        self::assertCount(1, $result->model->floors[0]->engineeringElements);
        self::assertContains('geometry_reference_unresolved', array_map(static fn ($item): string => $item->code, $result->clarifications));
    }

    #[Test]
    public function all_eight_questions_keep_business_order_separate_from_geometry_clarifications(): void
    {
        $questions = array_map(static fn (string $key): SketchQuestionData => new SketchQuestionData($key), SketchQuestionData::KEYS);
        $result = (new BuildingModelAssembler)->assembleVision(new VisionBuildingModelInputData(
            new ScaleResolutionData('missing', null, [], 'geometry_scale_unconfirmed'),
            (new GeometryFusionService)->fuse([self::sourceRoom('room-1', 'e1', [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]])]),
            [], $questions, ['e1' => 11], 'vision-fusion:v1', 'floor-1',
        ));

        self::assertSame(SketchQuestionData::KEYS, array_map(static fn (SketchQuestionData $item): string => $item->key, $result->questions));
        self::assertSame(['geometry_scale_unconfirmed'], array_map(static fn ($item): string => $item->code, $result->clarifications));
    }

    private static function floor(string $roomKey, int $width): FloorData
    {
        return new FloorData('floor-1', 0, 2.8, [
            new RoomData($roomKey, null, [[0, 0], [$width, 0], [$width, 3], [0, 3]], [$width === 4 ? 11 : 12], 0.9, 'confirmed'),
        ], [], [], [], [$width === 4 ? 11 : 12], 0.9, 'confirmed');
    }

    private static function sourceRoom(string $key, string $evidence, array $geometry): FusedGeometryElementData
    {
        return self::sourceElement($key, 'room', $evidence, ['polygon' => $geometry]);
    }

    private static function sourceElement(string $key, string $type, string $evidence, array $geometry): FusedGeometryElementData
    {
        return new FusedGeometryElementData($key, $type, $geometry, 'vision', $evidence, 'sha256:'.str_repeat('b', 64), 1, 'source:v1', 'runtime:v1', 'model:v1', 0.9);
    }

    private static function userAssumption(string $key, int|float|string|array $value, string $evidence): SketchAssumption
    {
        return new SketchAssumption(new SketchValueData($key, $value), new SketchProvenanceData('user', 7, $evidence, 'sha256:'.str_repeat('c', 64), 1, 'source:v1'), 1.0, true);
    }
}
