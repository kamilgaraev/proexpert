<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\AssumptionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\EngineeringElementData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\OpeningData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\WallData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormalizedBuildingModelDataTest extends TestCase
{
    #[Test]
    public function model_has_exact_canonical_round_trip_and_hash(): void
    {
        $model = self::model();
        $reordered = self::reverseAssociativeKeys($model->toArray());

        self::assertSame($model->toArray(), NormalizedBuildingModelData::fromArray($reordered)->toArray());
        self::assertSame($model->contentVersion(), NormalizedBuildingModelData::fromArray($reordered)->contentVersion());
        self::assertSame([101, 102, 103, 104, 105], $model->evidenceIds);
        self::assertSame('sha256:', substr($model->contentVersion(), 0, 7));
    }

    #[Test]
    public function nested_input_arrays_are_copied_and_cannot_mutate_model(): void
    {
        $polygon = [[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]];
        $room = new RoomData('room-1', 'Кухня', $polygon, [101], 0.94, 'confirmed');
        $polygon[0][0] = 999.0;

        self::assertSame(0.0, $room->polygon[0][0]);
        self::assertSame('Кухня', $room->name);
    }

    #[Test]
    public function canonical_polygon_is_open_counter_clockwise_and_has_stable_origin(): void
    {
        $room = new RoomData(
            'room-1',
            null,
            [[4.0, 3.0], [4.0, 0.0], [0.0, 0.0], [0.0, 3.0], [4.0, 3.0]],
            [101],
            1.0,
            'confirmed',
        );

        self::assertSame([[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]], $room->polygon);
    }

    #[Test]
    #[DataProvider('invalidModelProvider')]
    public function invalid_models_fail_closed(callable $factory, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $factory();
    }

    public static function invalidModelProvider(): iterable
    {
        yield 'unknown schema key' => [
            static fn () => NormalizedBuildingModelData::fromArray(self::model()->toArray() + ['raw_text' => 'secret']),
            'exact keys',
        ];
        yield 'wrong exact value type' => [
            static function (): NormalizedBuildingModelData {
                $data = self::model()->toArray();
                $data['floors'][0]['confidence'] = 'high';

                return NormalizedBuildingModelData::fromArray($data);
            },
            'type',
        ];
        yield 'unknown scale leaks metric floor geometry' => [
            static fn () => new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData('f', 0.0, null, [], [], [], [], [1], 1.0, 'unknown')], [], 'building-model:v1'),
            'Unknown scale',
        ];
        yield 'estimated geometry cannot be confirmed' => [
            static fn () => new NormalizedBuildingModelData('m', 'estimated', 0.01, [new FloorData('f', 0.0, 2.8, [], [], [], [], [1], 1.0, 'confirmed')], [], 'building-model:v1'),
            'Estimated scale',
        ];
        yield 'estimated scale without blocker' => [
            static fn () => new NormalizedBuildingModelData('m', 'estimated', 0.01, [new FloorData('f', 0.0, 2.8, [], [], [], [], [1], 1.0, 'estimated')], [], 'building-model:v1'),
            'scale_estimated',
        ];
        yield 'unknown scale without blocker' => [
            static fn () => new NormalizedBuildingModelData('m', 'unknown', null, [new FloorData('f', null, null, [], [], [], [], [1], 1.0, 'unknown')], [], 'building-model:v1'),
            'scale_missing',
        ];
        yield 'confirmed scale with stale scale blocker' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', 0.01, [new FloorData('f', 0.0, 2.8, [], [], [], [], [1], 1.0, 'confirmed')], [new AssumptionData('scale_estimated', 'blocking', ['f'], [1], true)], 'building-model:v1'),
            'stale scale blocker',
        ];
        yield 'non finite scale' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', INF, [], [], 'building-model:v1'),
            'scale',
        ];
        yield 'duplicate element key' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
                new FloorData('f', 0.0, 2.8, [new RoomData('same', null, [[0, 0], [1, 0], [0, 1]], [1], 1, 'confirmed')], [new WallData('same', [0, 0], [1, 0], 0.2, 2.8, [2], 1, 'confirmed')], [], [], [3], 1, 'confirmed'),
            ], [], 'building-model:v1'),
            'globally unique',
        ];
        yield 'self crossing polygon' => [
            static fn () => new RoomData('r', null, [[0, 0], [2, 2], [0, 2], [2, 0]], [1], 1, 'confirmed'),
            'self-intersect',
        ];
        yield 'opening does not fit wall' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', 0.01, [new FloorData(
                'f', 0, 2.8, [],
                [new WallData('w', [0, 0], [2, 0], 0.2, 2.8, [1], 1, 'confirmed')],
                [new OpeningData('o', 'w', 'door', 1.5, 1.0, 2.0, [2], 1, 'confirmed')],
                [], [3], 1, 'confirmed',
            )], [], 'building-model:v1'),
            'fit wall',
        ];
        yield 'dangling room reference' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', 0.01, [new FloorData(
                'f', 0, 2.8, [], [], [],
                [new EngineeringElementData('e', 'outlet', [0, 0], 'missing', [1], 1, 'confirmed')],
                [2], 1, 'confirmed',
            )], [], 'building-model:v1'),
            'room reference',
        ];
        yield 'privacy-bearing assumption' => [
            static fn () => new AssumptionData('scale_conflict', 'blocking', ['floor-1'], [1], true, ['filename' => 'client.pdf']),
            'exact keys',
        ];
        yield 'privacy-bearing room label' => [
            static fn () => new RoomData('r', 'client@example.test', null, [1], 1, 'unknown'),
            'name',
        ];
        yield 'negative wall dimension' => [
            static fn () => new WallData('w', [0, 0], [1, 0], -0.2, 2.8, [1], 1, 'confirmed'),
            'thickness',
        ];
        yield 'oversized polygon' => [
            static fn () => new RoomData('r', null, array_map(
                static fn (int $index): array => [cos($index * 2 * M_PI / 2049), sin($index * 2 * M_PI / 2049)],
                range(0, 2048),
            ), [1], 1, 'confirmed'),
            'vertex count',
        ];
        yield 'incoherent floor elevations' => [
            static fn () => new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
                new FloorData('f1', 0, 3, [], [], [], [], [1], 1, 'confirmed'),
                new FloorData('f2', 2.5, 3, [], [], [], [], [2], 1, 'confirmed'),
            ], [], 'building-model:v1'),
            'coherent',
        ];
        yield 'more than ten thousand elements' => [
            static function (): NormalizedBuildingModelData {
                $floors = [];
                for ($floor = 0; $floor < 100; $floor++) {
                    $rooms = [];
                    for ($room = 0; $room < 101; $room++) {
                        $rooms[] = new RoomData("room-{$floor}-{$room}", null, null, [1], 1, 'unknown');
                    }
                    $floors[] = new FloorData("floor-{$floor}", null, null, $rooms, [], [], [], [1], 1, 'unknown');
                }

                return new NormalizedBuildingModelData('m', 'unknown', null, $floors, [
                    new AssumptionData('scale_missing', 'blocking', ['floor-0'], [1], true),
                ], 'building-model:v1');
            },
            'element count',
        ];
        yield 'consecutive duplicate polygon vertex' => [
            static fn () => new RoomData('r', null, [[0, 0], [2, 0], [2, 0], [0, 2]], [1], 1, 'confirmed'),
            'zero-length',
        ];
        yield 'repeated non adjacent polygon vertex' => [
            static fn () => new RoomData('r', null, [[0, 0], [3, 0], [3, 3], [0, 3], [3, 0]], [1], 1, 'confirmed'),
            'repeated',
        ];
        yield 'non adjacent endpoint touches edge' => [
            static fn () => new RoomData('r', null, [[0, 0], [4, 0], [4, 4], [2, 0], [0, 4]], [1], 1, 'confirmed'),
            'self-intersect',
        ];
        yield 'collinear non adjacent edges overlap' => [
            static fn () => new RoomData('r', null, [[0, 0], [4, 0], [4, 4], [0, 4], [1, 0], [3, 0]], [1], 1, 'confirmed'),
            'self-intersect',
        ];
        yield 'adjacent collinear edges backtrack and overlap' => [
            static fn () => new RoomData('r', null, [[0, 0], [4, 0], [2, 0], [2, 2], [0, 2]], [1], 1, 'confirmed'),
            'self-intersect',
        ];
    }

    private static function model(): NormalizedBuildingModelData
    {
        return new NormalizedBuildingModelData(
            'm',
            'confirmed',
            0.01,
            [new FloorData(
                'floor-1',
                0.0,
                2.8,
                [new RoomData('room-1', 'Кухня', [[0, 0], [4, 0], [4, 3], [0, 3]], [101], 0.94, 'confirmed')],
                [new WallData('wall-1', [0, 0], [4, 0], 0.2, 2.8, [102], 0.91, 'confirmed')],
                [new OpeningData('opening-1', 'wall-1', 'door', 1.0, 0.9, 2.1, [103], 0.88, 'confirmed')],
                [new EngineeringElementData('socket-1', 'outlet', [2, 1], 'room-1', [104], 0.85, 'confirmed')],
                [105],
                0.97,
                'confirmed',
            )],
            [],
            'building-model:v1',
        );
    }

    private static function reverseAssociativeKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::reverseAssociativeKeys(...), $value);
        }
        $result = [];
        foreach (array_reverse(array_keys($value)) as $key) {
            $result[$key] = self::reverseAssociativeKeys($value[$key]);
        }

        return $result;
    }
}
