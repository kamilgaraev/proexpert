<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use PHPUnit\Framework\TestCase;

final class QuantityEvidenceTest extends TestCase
{
    public function test_formula_provenance_round_trips_with_stable_versions_and_canonical_identity(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate([
            'model_version' => 'building-model.v7',
            'scale' => ['status' => 'confirmed', 'unit' => 'm'],
            'rooms' => [['id' => 'r', 'area' => '12.50', 'evidence_ids' => ['z', 'a']]],
        ]);
        $quantity = $result->get('floor_area');

        self::assertNotNull($quantity);
        self::assertSame('floor.area.sum', $quantity->formulaKey);
        self::assertSame('1.0.0', $quantity->formulaVersion);
        self::assertSame('building-model.v7', $quantity->modelVersion);
        self::assertSame(['a', 'z'], $quantity->evidenceIds);
        self::assertSame($quantity->toArray(), QuantityData::fromArray($quantity->toArray())->toArray());
    }

    public function test_large_model_has_linear_bounded_output(): void
    {
        $rooms = [];
        for ($i = 0; $i < 5000; $i++) {
            $rooms[] = ['id' => 'r-'.$i, 'area' => '1.0000001', 'evidence_ids' => ['e-'.$i]];
        }

        $result = (new BuildingQuantityCalculator)->calculate([
            'model_version' => 'building-model.v1', 'scale' => ['status' => 'confirmed', 'unit' => 'm'], 'rooms' => $rooms,
        ]);

        self::assertSame('5000.000500', $result->get('floor_area')?->amount);
        self::assertCount(2, $result->all());
        self::assertLessThanOrEqual(5000, count($result->get('floor_area')?->evidenceIds ?? []));
    }
}
