<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\BuildingGeometryMutator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\AssumptionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\OpeningData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\WallData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildingGeometryMutatorTest extends TestCase
{
    #[Test]
    #[DataProvider('operations')]
    public function every_allowed_operation_mutates_the_stable_element(string $path, mixed $value, callable $read): void
    {
        $result = (new BuildingGeometryMutator)->mutate($this->model(), $this->command($path, $value), 99)->toArray();

        self::assertEquals($value, $read($result));
        self::assertContains(99, $result['evidence_ids']);
    }

    public static function operations(): iterable
    {
        yield 'floor height' => ['/floors/floor-1/height_m', 3.2, static fn (array $m) => $m['floors'][0]['height_m']];
        yield 'room name' => ['/floors/floor-1/rooms/room-1/name', 'Кухня', static fn (array $m) => $m['floors'][0]['rooms'][0]['name']];
        yield 'room polygon' => ['/floors/floor-1/rooms/room-1/polygon', [[0, 0], [3, 0], [3, 2]], static fn (array $m) => $m['floors'][0]['rooms'][0]['polygon']];
        yield 'wall start' => ['/floors/floor-1/walls/wall-1/start', [0, 1], static fn (array $m) => $m['floors'][0]['walls'][0]['start']];
        yield 'wall end' => ['/floors/floor-1/walls/wall-1/end', [6, 0], static fn (array $m) => $m['floors'][0]['walls'][0]['end']];
        yield 'wall type' => ['/floors/floor-1/walls/wall-1/type', 'bearing', static fn (array $m) => $m['floors'][0]['walls'][0]['type']];
        yield 'wall material' => ['/floors/floor-1/walls/wall-1/material', 'brick', static fn (array $m) => $m['floors'][0]['walls'][0]['material']];
        yield 'wall height' => ['/floors/floor-1/walls/wall-1/height_m', 3.0, static fn (array $m) => $m['floors'][0]['walls'][0]['height_m']];
        yield 'opening type' => ['/floors/floor-1/openings/opening-1/type', 'window', static fn (array $m) => $m['floors'][0]['openings'][0]['type']];
        yield 'opening offset' => ['/floors/floor-1/openings/opening-1/offset_m', 2.0, static fn (array $m) => $m['floors'][0]['openings'][0]['offset_m']];
        yield 'opening width' => ['/floors/floor-1/openings/opening-1/width_m', 1.2, static fn (array $m) => $m['floors'][0]['openings'][0]['width_m']];
        yield 'opening height' => ['/floors/floor-1/openings/opening-1/height_m', 2.2, static fn (array $m) => $m['floors'][0]['openings'][0]['height_m']];
    }

    #[Test]
    public function foreign_element_key_is_rejected_without_partial_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BuildingGeometryMutator)->mutate($this->model(), $this->command('/floors/floor-1/rooms/room-foreign/name', 'x'));
    }

    #[Test]
    public function replacing_with_current_value_has_identical_semantic_version(): void
    {
        $model = $this->model();
        $result = (new BuildingGeometryMutator)->mutate($model, $this->command('/floors/floor-1/rooms/room-1/name', 'Комната'));

        self::assertSame(NormalizedBuildingModelData::fromArray($model)->contentVersion(), $result->contentVersion());
    }

    #[Test]
    public function scale_and_room_geometry_can_be_confirmed_in_one_command(): void
    {
        $command = new GeometryConfirmationCommand(
            1,
            2,
            3,
            4,
            5,
            'sha256:'.str_repeat('a', 64),
            'sha256:'.str_repeat('b', 64),
            ['pixel_start' => [0, 0], 'pixel_end' => [1000, 0], 'meters' => 10],
            [[
                'op' => 'replace',
                'path' => '/floors/floor-1/rooms/room-1/polygon',
                'value' => [[0, 0], [5, 0], [5, 4], [0, 4]],
            ]],
        );

        $result = (new BuildingGeometryMutator)->mutate($this->unscaledModel(), $command, 99)->toArray();

        self::assertSame('confirmed', $result['scale_status']);
        self::assertSame(0.01, $result['scale_meters_per_unit']);
        self::assertSame([[0.0, 0.0], [5.0, 0.0], [5.0, 4.0], [0.0, 4.0]], $result['floors'][0]['rooms'][0]['polygon']);
        self::assertTrue($result['metrics']['complete']);
    }

    #[Test]
    #[DataProvider('legacyWallFields')]
    public function legacy_wall_without_new_fields_can_replace_each_field_independently(string $field, string $value): void
    {
        $model = $this->model();
        unset($model['floors'][0]['walls'][0]['type'], $model['floors'][0]['walls'][0]['material']);

        $result = (new BuildingGeometryMutator)->mutate($model, $this->command("/floors/floor-1/walls/wall-1/{$field}", $value))->toArray();

        self::assertSame($value, $result['floors'][0]['walls'][0][$field]);
        self::assertArrayHasKey($field === 'type' ? 'material' : 'type', $result['floors'][0]['walls'][0]);
        self::assertSame($result, NormalizedBuildingModelData::fromArray($result)->toArray());
    }

    public static function legacyWallFields(): iterable
    {
        yield 'type' => ['type', 'bearing'];
        yield 'material' => ['material', 'brick'];
    }

    private function command(string $path, mixed $value): GeometryConfirmationCommand
    {
        return new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), null, [
            ['op' => 'replace', 'path' => $path, 'value' => $value],
        ]);
    }

    private function model(): array
    {
        return (new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
            new FloorData('floor-1', 0, 3, [new RoomData('room-1', 'Комната', [[0, 0], [5, 0], [5, 4]], [1], 1, 'confirmed')],
                [new WallData('wall-1', [0, 0], [5, 0], 0.2, 3, [1], 1, 'confirmed')],
                [new OpeningData('opening-1', 'wall-1', 'door', 1, 1, 2, [1], 1, 'confirmed')], [], [1], 1, 'confirmed'),
        ], [], 'building-model:v1'))->toArray();
    }

    private function unscaledModel(): array
    {
        return (new NormalizedBuildingModelData('m', 'unknown', null, [
            new FloorData('floor-1', null, null, [new RoomData('room-1', 'Комната', null, [1], 1, 'unknown')], [], [], [], [1], 1, 'unknown'),
        ], [new AssumptionData('scale_missing', 'blocking', ['floor-1'], [1], true)], 'building-model:v1'))->toArray();
    }
}
