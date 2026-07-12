<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use PHPUnit\Framework\TestCase;

final class BuildingQuantityCalculatorTest extends TestCase
{
    public function test_rectangular_room_quantities_are_exact_and_deterministic(): void
    {
        $model = $this->model([
            'rooms' => [[
                'id' => 'room-1',
                'polygon' => [['0', '0'], ['4', '0'], ['4', '3'], ['0', '3']],
                'height' => '2.8',
                'evidence_ids' => ['ev-room'],
            ]],
            'walls' => [[
                'id' => 'wall-1', 'length' => '14', 'height' => '2.8',
                'opening_ids' => ['door-1', 'window-1'], 'evidence_ids' => ['ev-wall'],
            ]],
            'openings' => [
                ['id' => 'door-1', 'wall_id' => 'wall-1', 'width' => '0.9', 'height' => '2', 'evidence_ids' => ['ev-door']],
                ['id' => 'window-1', 'wall_id' => 'wall-1', 'width' => '1.2', 'height' => '2', 'evidence_ids' => ['ev-window']],
            ],
        ]);

        $result = (new BuildingQuantityCalculator)->calculate($model);

        self::assertSame('12.000000', $result->get('floor_area')?->amount);
        self::assertSame('12.000000', $result->get('ceiling_area')?->amount);
        self::assertSame('39.200000', $result->get('gross_wall_area')?->amount);
        self::assertSame('35.000000', $result->get('net_wall_area')?->amount);
        self::assertSame('evidenced', $result->get('net_wall_area')?->source->value);
        self::assertSame(['ev-door', 'ev-wall', 'ev-window'], $result->get('net_wall_area')?->evidenceIds);
    }

    public function test_exact_decimal_math_is_order_independent_and_never_rounds_between_items(): void
    {
        $rooms = [
            ['id' => 'b', 'area' => '0.0000005', 'evidence_ids' => ['b']],
            ['id' => 'a', 'area' => '0.0000005', 'evidence_ids' => ['a']],
            ['id' => 'c', 'area' => '0.1', 'evidence_ids' => ['c']],
        ];
        $first = (new BuildingQuantityCalculator)->calculate($this->model(['rooms' => $rooms]));
        $second = (new BuildingQuantityCalculator)->calculate($this->model(['rooms' => array_reverse($rooms)]));

        self::assertSame('0.100001', $first->get('floor_area')?->amount);
        self::assertSame($first->toArray(), $second->toArray());
    }

    public function test_shared_room_overlap_is_not_double_counted(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model(['rooms' => [
            ['id' => 'one', 'polygon' => [['0', '0'], ['4', '0'], ['4', '3'], ['0', '3']], 'evidence_ids' => ['one']],
            ['id' => 'duplicate', 'polygon' => [['0', '0'], ['4', '0'], ['4', '3'], ['0', '3']], 'evidence_ids' => ['two']],
        ]]));

        self::assertSame('12.000000', $result->get('floor_area')?->amount);
        self::assertContains('duplicate_room_geometry', array_column($result->diagnostics, 'code'));
    }

    public function test_missing_scale_or_operands_produce_blockers_not_invented_quantities(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate([
            'model_version' => 'building-model.v1',
            'scale' => ['status' => 'unconfirmed'],
            'rooms' => [['id' => 'pixels', 'polygon' => [[0, 0], [400, 0], [400, 300], [0, 300]], 'coordinate_unit' => 'px']],
            'walls' => [['id' => 'wall', 'length' => '4', 'evidence_ids' => ['length-only']]],
        ]);

        self::assertNull($result->get('floor_area'));
        self::assertNull($result->get('gross_wall_area'));
        self::assertContains('unconfirmed_scale', array_column($result->diagnostics, 'code'));
        self::assertContains('missing_wall_height', array_column($result->diagnostics, 'code'));
    }

    public function test_explicit_assumptions_are_estimated_and_taint_aggregates(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'rooms' => [
                ['id' => 'known', 'area' => '10', 'evidence_ids' => ['known']],
                ['id' => 'assumed', 'area' => '5', 'source' => 'estimated', 'assumptions' => ['user_footprint_area']],
            ],
        ]));

        self::assertSame('15.000000', $result->get('floor_area')?->amount);
        self::assertSame('estimated', $result->get('floor_area')?->source->value);
        self::assertSame(['user_footprint_area'], $result->get('floor_area')?->assumptions);
    }

    public function test_foundation_roof_and_engineering_require_typed_measurements(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'foundations' => [['id' => 'f', 'length' => '10', 'width' => '0.5', 'depth' => '0.8', 'evidence_ids' => ['f']]],
            'roofs' => [['id' => 'r', 'area' => '42.125', 'evidence_ids' => ['r']]],
            'engineering' => [['id' => 'e', 'system' => 'water', 'measurement' => 'length', 'amount' => '18.25', 'unit' => 'm', 'evidence_ids' => ['e']]],
        ]));

        self::assertSame('4.000000', $result->get('foundation_volume')?->amount);
        self::assertSame('42.125000', $result->get('roof_area')?->amount);
        self::assertSame('18.250000', $result->get('engineering.water.length')?->amount);

        $insufficient = (new BuildingQuantityCalculator)->calculate($this->model([
            'foundations' => [['id' => 'f', 'length' => '10']],
            'roofs' => [['id' => 'r', 'footprint_area' => '42']],
            'engineering' => [['id' => 'e', 'system' => 'water']],
        ]));
        self::assertNull($insufficient->get('foundation_volume'));
        self::assertNull($insufficient->get('roof_area'));
        self::assertContains('insufficient_foundation_geometry', array_column($insufficient->diagnostics, 'code'));
        self::assertContains('insufficient_roof_geometry', array_column($insufficient->diagnostics, 'code'));
        self::assertContains('insufficient_engineering_measurement', array_column($insufficient->diagnostics, 'code'));
    }

    public function test_invalid_references_duplicates_and_negative_net_are_blocking(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'walls' => [
                ['id' => 'w', 'length' => '2', 'height' => '2', 'opening_ids' => ['o', 'o'], 'evidence_ids' => ['w']],
                ['id' => 'w2', 'length' => '2', 'height' => '2', 'opening_ids' => ['x'], 'evidence_ids' => ['w2']],
            ],
            'openings' => [
                ['id' => 'o', 'wall_id' => 'w', 'width' => '3', 'height' => '3', 'evidence_ids' => ['o']],
                ['id' => 'x', 'wall_id' => 'missing', 'width' => '1', 'height' => '1', 'evidence_ids' => ['x']],
            ],
        ]));

        self::assertNull($result->get('net_wall_area'));
        self::assertContains('duplicate_opening_reference', array_column($result->diagnostics, 'code'));
        self::assertContains('opening_wall_reference_conflict', array_column($result->diagnostics, 'code'));
        self::assertContains('opening_area_exceeds_wall_area', array_column($result->diagnostics, 'code'));
    }

    /** @param array<string, mixed> $overrides */
    private function model(array $overrides = []): array
    {
        return array_replace([
            'model_version' => 'building-model.v1',
            'scale' => ['status' => 'confirmed', 'unit' => 'm'],
            'rooms' => [], 'walls' => [], 'openings' => [], 'foundations' => [], 'roofs' => [], 'engineering' => [],
        ], $overrides);
    }
}
