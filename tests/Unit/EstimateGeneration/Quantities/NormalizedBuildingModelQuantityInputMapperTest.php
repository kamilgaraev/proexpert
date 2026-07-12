<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\EngineeringElementData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\OpeningData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\WallData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use PHPUnit\Framework\TestCase;

final class NormalizedBuildingModelQuantityInputMapperTest extends TestCase
{
    public function test_all_floor_elements_are_flattened_without_binary_float_strings(): void
    {
        $model = new NormalizedBuildingModelData('m', 'confirmed', 1.0, [
            new FloorData('floor-1', 0.0, 2.8,
                [new RoomData('room-1', null, [[0.0, 0.0], [0.1, 0.0], [0.1, 0.2], [0.0, 0.2]], [11], 0.91, 'confirmed')],
                [new WallData('wall-1', [0.0, 0.0], [0.1, 0.0], 0.2, 2.8, [12], 0.92, 'confirmed')],
                [new OpeningData('opening-1', 'wall-1', 'door', 0.0, 0.03, 2.0, [13], 0.93, 'confirmed')],
                [new EngineeringElementData('eng-1', 'water_point', [0.05, 0.05], 'room-1', [14], 0.94, 'confirmed')],
                [10], 0.9, 'confirmed'),
        ], [], 'building-model:v1');

        $mapped = (new NormalizedBuildingModelQuantityInputMapper)->map($model);

        self::assertSame([['0.0', '0.0'], ['0.1', '0.0'], ['0.1', '0.2'], ['0.0', '0.2']], $mapped['rooms'][0]['polygon']);
        self::assertSame('0.1', $mapped['walls'][0]['length']);
        self::assertSame('2.8', $mapped['walls'][0]['height']);
        self::assertSame(['11'], $mapped['rooms'][0]['evidence_ids']);
        self::assertSame('evidenced', $mapped['rooms'][0]['source']);
        self::assertSame('0.91', $mapped['rooms'][0]['confidence']);
        self::assertSame('water', $mapped['engineering'][0]['system']);
        self::assertSame('count', $mapped['engineering'][0]['measurement']);

        $quantities = (new BuildingQuantityCalculator)->calculate($mapped);
        self::assertSame('0.020000', $quantities->get('floor_area')?->amount);
    }

    public function test_confirmed_sewer_route_produces_evidenced_length_quantity(): void
    {
        $model = new NormalizedBuildingModelData('m', 'confirmed', 1.0, [
            new FloorData('floor-1', 0.0, null, [], [], [], [
                new EngineeringElementData('riser-110', 'sewer_route', [1.8, 0.8], null, [17], 1.0, 'confirmed', 3.3),
            ], [17], 1.0, 'confirmed'),
        ], [], 'building-model:v1');

        $quantities = (new BuildingQuantityCalculator)->calculate(
            (new NormalizedBuildingModelQuantityInputMapper)->map($model),
        );

        self::assertSame('3.300000', $quantities->get('engineering.sewer.length')?->amount);
        self::assertSame(['17'], $quantities->get('engineering.sewer.length')?->evidenceIds);
    }
}
