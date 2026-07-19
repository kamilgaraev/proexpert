<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialQuantityScenarioCatalogTest extends TestCase
{
    #[Test]
    public function confirmed_residential_plans_enable_traceable_preliminary_scope_for_required_sections(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1', 'room:2']),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
            'upper_floor_internal_area' => $this->quantity('upper_floor_internal_area', '79.500000', ['room:2']),
        ], $this->model(), [
            'object' => [
                'object_type' => 'house',
                'floors' => 2,
                'roof_type' => 'pitched',
                'description' => 'Индивидуальный жилой дом с инженерными системами',
            ],
        ]);

        foreach ([
            'foundation.prep',
            'walls.lintels',
            'stairs.flights',
            'stairs.railings',
            'roof.rafters',
            'roof.area',
            'roof.gutter',
            'openings.windows',
            'openings.doors',
            'electrical.main_cable',
            'electrical.power_lines',
            'electrical.panel',
            'electrical.outlets',
            'electrical.switches',
            'electrical.grounding',
            'lighting.fixtures',
            'plumbing.pipe',
            'sewerage.pipe',
            'heating.unit',
            'heating.pipe',
            'heating.radiators',
            'ventilation.air_exchange',
            'sanitary.waterproofing',
            'rough.floor',
            'sanitary.tile',
            'sanitary.points',
            'sewerage.outlets',
            'sewerage.risers',
            'sewerage.revisions',
        ] as $quantityKey) {
            self::assertArrayHasKey($quantityKey, $result->quantities, $quantityKey);
            self::assertTrue(ResidentialQuantityScenarioCatalog::owns($result->quantities[$quantityKey]), $quantityKey);
            self::assertNotSame([], $result->quantities[$quantityKey]->evidenceIds, $quantityKey);
            self::assertGreaterThan(0, (float) $result->quantities[$quantityKey]->amount, $quantityKey);
        }

        self::assertArrayNotHasKey('electrical.trays', $result->quantities);
        self::assertArrayNotHasKey('ventilation.office_points', $result->quantities);
        self::assertArrayNotHasKey('ventilation.warehouse_points', $result->quantities);
        foreach ($result->omissions as $omission) {
            self::assertTrue(QuantityCoverageWarning::isValid($omission), json_encode($omission, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function documented_house_facts_produce_versioned_preliminary_quantities_with_review_contract(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1', 'room:2']),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
            'upper_floor_internal_area' => $this->quantity('upper_floor_internal_area', '79.500000', ['room:2']),
        ], $this->model(), ['object' => ['object_type' => 'house', 'floors' => 2, 'roof_type' => 'pitched']]);

        self::assertSame('152.955000', $result->quantities['roof.area']->amount);
        self::assertSame('152.955000', $result->quantities['roof.rafters']->amount);
        self::assertSame('46.834688', $result->quantities['roof.gutter']->amount);
        self::assertSame('23.136000', $result->quantities['openings.windows']->amount);
        self::assertSame('9.000000', $result->quantities['openings.doors']->amount);
        self::assertSame('8.000000', $result->quantities['stairs.flights']->amount);
        self::assertSame('8.000000', $result->quantities['stairs.railings']->amount);
        self::assertSame('77.120000', $result->quantities['electrical.main_cable']->amount);
        self::assertSame('1.000000', $result->quantities['electrical.panel']->amount);
        self::assertGreaterThan(0, (float) $result->quantities['electrical.outlets']->amount);
        self::assertGreaterThan(0, (float) $result->quantities['electrical.switches']->amount);
        self::assertGreaterThan(0, (float) $result->quantities['lighting.fixtures']->amount);
        self::assertSame('67.480000', $result->quantities['plumbing.pipe']->amount);
        self::assertSame('12.980000', $result->quantities['sanitary.waterproofing']->amount);
        self::assertGreaterThan(0, (float) $result->quantities['sanitary.points']->amount);
        self::assertGreaterThan(0, (float) $result->quantities['sanitary.tile']->amount);
        self::assertArrayNotHasKey('electrical.trays', $result->quantities);

        foreach ($result->quantities as $quantity) {
            self::assertSame(QuantitySource::Estimated, $quantity->source, $quantity->key);
            self::assertTrue(ResidentialQuantityScenarioCatalog::owns($quantity), $quantity->key);
            self::assertSame(ResidentialQuantityScenarioCatalog::VERSION, $quantity->formulaVersion, $quantity->key);
            self::assertNotSame([], $quantity->evidenceIds, $quantity->key);
            self::assertContains(ResidentialQuantityScenarioCatalog::SCENARIO_ID, $quantity->assumptions, $quantity->key);
            self::assertSame([], $quantity->reviewBlockers, $quantity->key);
            self::assertSame(0.62, $quantity->formulaInputs['scenario']['confidence'], $quantity->key);
            self::assertSame(['preliminary_quantity_scenario'], $quantity->formulaInputs['scenario']['warnings'], $quantity->key);
        }

        self::assertNotContains('roof.rafters', array_column($result->omissions, 'quantity_key'));
        self::assertContains('networks.external', array_column($result->omissions, 'quantity_key'));
        self::assertContains('electrical.trays', array_column($result->omissions, 'quantity_key'));
        self::assertNotContains('stairs.flights', array_column($result->omissions, 'quantity_key'));
        self::assertNotContains('stairs.railings', array_column($result->omissions, 'quantity_key'));
        self::assertNotContains('openings.windows', array_column($result->omissions, 'quantity_key'));
        self::assertNotContains('heating.radiators', array_column($result->omissions, 'quantity_key'));
        self::assertNotContains('ventilation.air_exchange', array_column($result->omissions, 'quantity_key'));
    }

    #[Test]
    public function documented_internal_ceiling_area_feeds_rough_and_finish_ceiling_work(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1', 'room:2']),
            'ceiling_area' => $this->quantity('ceiling_area', '192.800000', ['room:1', 'room:2']),
        ], $this->model(), ['object' => ['object_type' => 'house']]);

        self::assertSame('192.800000', $result->quantities['rough.ceiling']->amount);
        self::assertSame('192.800000', $result->quantities['finish.ceiling']->amount);
        self::assertSame('ceiling_area', $result->quantities['rough.ceiling']->formulaInputs['source_quantity']['key']);
        self::assertSame('ceiling_area', $result->quantities['finish.ceiling']->formulaInputs['source_quantity']['key']);
    }

    #[Test]
    public function preliminary_house_scope_estimates_connection_counts_without_inventing_fixture_types(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1', 'room:2']),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
        ], $this->model(), [
            'object' => ['object_type' => 'house', 'floors' => 2, 'roof_type' => 'pitched'],
        ]);

        foreach ([
            'sanitary.points',
            'sewerage.outlets',
            'sewerage.risers',
            'sewerage.revisions',
            'sanitary.tile',
        ] as $quantityKey) {
            self::assertArrayHasKey($quantityKey, $result->quantities, $quantityKey);
            self::assertGreaterThan(0, (float) $result->quantities[$quantityKey]->amount, $quantityKey);
            self::assertContains('preliminary_quantity_scenario', $result->quantities[$quantityKey]->formulaInputs['scenario']['warnings']);
        }

        self::assertArrayHasKey('plumbing.pipe', $result->quantities);
        self::assertArrayHasKey('sewerage.pipe', $result->quantities);
        self::assertArrayHasKey('sanitary.waterproofing', $result->quantities);
    }

    #[Test]
    public function catalog_is_not_applied_to_non_residential_objects(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1']),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
        ], $this->model(), ['object' => ['object_type' => 'mixed_warehouse_office']]);

        self::assertSame([], $result->quantities);
        self::assertSame([], $result->omissions);
    }

    #[Test]
    public function residential_description_enables_catalog_when_object_type_is_generic(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1']),
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
        ], $this->model(), [
            'object' => ['object_type' => 'building', 'description' => 'Индивидуальный жилой дом'],
        ]);

        self::assertArrayHasKey('electrical.main_cable', $result->quantities);
        self::assertArrayHasKey('plumbing.pipe', $result->quantities);
        self::assertContains('roof.area', array_column($result->omissions, 'quantity_key'));
    }

    #[Test]
    public function missing_documentary_operand_is_reported_and_never_replaced_by_total_area(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->quantity('floor_area', '192.800000', ['room:1']),
        ], $this->model(), ['object' => ['object_type' => 'house']]);

        self::assertArrayNotHasKey('roof.area', $result->quantities);
        self::assertContains('roof.area', array_column($result->omissions, 'quantity_key'));
        self::assertContains('roof.gutter', array_column($result->omissions, 'quantity_key'));
    }

    #[Test]
    public function unknown_roof_type_uses_generic_projection_and_does_not_invent_a_gutter(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
        ], $this->model(), ['object' => ['object_type' => 'house']]);

        self::assertArrayNotHasKey('roof.area', $result->quantities);
        self::assertArrayNotHasKey('roof.gutter', $result->quantities);
        self::assertContains(
            ['quantity_key' => 'roof.gutter', 'reason' => 'roof_drainage_takeoff_missing', 'package_key' => 'roof'],
            $result->omissions,
        );
    }

    #[Test]
    public function flat_roof_uses_projection_area_and_does_not_invent_an_external_gutter(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'first_floor_internal_area' => $this->quantity('first_floor_internal_area', '113.300000', ['room:1']),
        ], $this->model(), ['object' => ['object_type' => 'house', 'roof_type' => 'flat']]);

        self::assertSame('113.300000', $result->quantities['roof.flat_area']->amount);
        self::assertArrayNotHasKey('roof.area', $result->quantities);
        self::assertArrayNotHasKey('roof.gutter', $result->quantities);
        self::assertContains(
            [
                'quantity_key' => 'roof.gutter',
                'reason' => 'external_gutter_not_inferred_for_flat_roof',
                'package_key' => 'roof',
            ],
            $result->omissions,
        );
    }

    private function quantity(string $key, string $amount, array $evidenceIds): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: 'm2',
            amount: $amount,
            formulaKey: 'document.rooms.'.$key,
            formulaVersion: '1.0.0',
            formulaInputs: ['items' => []],
            source: QuantitySource::Evidenced,
            evidenceIds: $evidenceIds,
            modelVersion: 'building-model:v1',
        );
    }

    private function model(): NormalizedBuildingModelData
    {
        $floor = static fn (string $key, array $rooms, array $evidenceIds): array => [
            'key' => $key,
            'elevation_m' => null,
            'height_m' => null,
            'rooms' => array_map(static fn (array $room): array => [
                'key' => $room[0],
                'name' => $room[1],
                'polygon' => null,
                'evidence_ids' => [$room[2]],
                'confidence' => 0.9,
                'geometry_certainty' => 'confirmed',
            ], $rooms),
            'walls' => [],
            'openings' => [],
            'engineering_elements' => [],
            'evidence_ids' => $evidenceIds,
            'confidence' => 0.9,
            'geometry_certainty' => 'confirmed',
        ];

        return NormalizedBuildingModelData::fromArray([
            'model_version' => 'building-model:v1',
            'coordinate_system' => 'metric-right-handed-2d:v1',
            'unit' => 'm',
            'scale_status' => 'confirmed',
            'scale_meters_per_unit' => 1.0,
            'floors' => [
                $floor('floor-1', [
                    ['room-1', 'Гостиная 30,0 м2', 1],
                    ['room-2', 'СУ 4,9', 2],
                    ['room-3', 'Кухня 18,0 м2', 3],
                ], [1, 2, 3]),
                $floor('floor-2', [
                    ['room-4', 'СУ 6,9', 4],
                    ['room-5', 'Холл 12,0 м2', 5],
                ], [4, 5]),
            ],
            'assumptions' => [],
            'evidence_ids' => [1, 2, 3, 4, 5],
            'metrics' => [
                'floor_count' => 2,
                'room_count' => 5,
                'wall_count' => 0,
                'opening_count' => 0,
                'engineering_element_count' => 0,
                'evidence_count' => 5,
                'minimum_confidence' => 0.9,
                'complete' => true,
            ],
        ]);
    }
}
