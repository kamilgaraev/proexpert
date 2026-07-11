<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelDetectionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
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

    private static function floor(string $roomKey, int $width): FloorData
    {
        return new FloorData('floor-1', 0, 2.8, [
            new RoomData($roomKey, null, [[0, 0], [$width, 0], [$width, 3], [0, 3]], [$width === 4 ? 11 : 12], 0.9, 'confirmed'),
        ], [], [], [], [$width === 4 ? 11 : 12], 0.9, 'confirmed');
    }
}
