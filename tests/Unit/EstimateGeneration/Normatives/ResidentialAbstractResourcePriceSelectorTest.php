<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialAbstractResourcePriceSelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialAbstractResourcePriceSelectorTest extends TestCase
{
    #[Test]
    public function residential_washbasin_converts_one_catalog_piece_to_one_normative_set(): void
    {
        $candidate = (object) [
            'price_resource_code' => '18.2.02.08-1001',
            'price_resource_name' => 'Умывальник керамический',
            'price_unit' => 'шт',
            'base_price' => 4500,
            'unit_price' => 4500,
            'price_id' => 20,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $selection = (new ResidentialAbstractResourcePriceSelector)->select(
            '17-01-001-14',
            '18.2.02.08',
            [$candidate],
            [4],
        );

        self::assertNotNull($selection);
        self::assertSame(4500.0, $selection['row']->unit_price);
        self::assertSame('компл', $selection['row']->price_unit);
        self::assertSame('washbasin_piece_per_set:1', $selection['assumption']);
    }

    #[Test]
    public function converts_mineral_wool_volume_price_to_area_using_explicit_residential_thickness(): void
    {
        $candidate = (object) [
            'price_resource_code' => '12.2.05.02-1001',
            'price_resource_name' => 'Плиты теплоизоляционные минераловатные',
            'price_unit' => 'м3',
            'base_price' => 10_000,
            'unit_price' => 10_000,
            'price_id' => 17,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $selection = (new ResidentialAbstractResourcePriceSelector)->select(
            '12-01-013-07',
            '12.2.05.02',
            [$candidate],
            [4],
        );

        self::assertNotNull($selection);
        self::assertSame(2_000.0, $selection['row']->unit_price);
        self::assertSame('м2', $selection['row']->price_unit);
        self::assertSame('mineral_wool_thickness_m:0.20', $selection['assumption']);
        self::assertSame('fsnb_2022_residential_converted_child_median:v1', $selection['policy']);
        self::assertSame(10_000.0, $selection['row']->project_resource_source_unit_price);
        self::assertSame('м3', $selection['row']->project_resource_source_price_unit);
        self::assertSame(0.20, $selection['row']->project_resource_conversion_factor);
    }

    #[Test]
    public function converts_regional_mineral_wool_volume_price_to_area_using_explicit_residential_thickness(): void
    {
        $candidate = (object) [
            'price_resource_code' => '12.2.05.02-0006',
            'price_resource_name' => 'Плиты теплоизоляционные минераловатные',
            'price_unit' => 'м3',
            'base_price' => 7078,
            'unit_price' => 7078,
            'price_id' => 17,
            'dataset_version_id' => 4,
            'regional_price_version_id' => 150,
            'price_dataset_source_type' => 'fgiscs',
        ];

        $selection = (new ResidentialAbstractResourcePriceSelector)->select(
            '12-01-013-07',
            '12.2.05.02',
            [$candidate],
            [4],
        );

        self::assertNotNull($selection);
        self::assertSame(1415.6, $selection['row']->unit_price);
        self::assertSame('м2', $selection['row']->price_unit);
        self::assertSame('regional_residential_converted_child_median:v1', $selection['policy']);
        self::assertSame(7078.0, $selection['row']->project_resource_source_unit_price);
        self::assertSame(0.20, $selection['row']->project_resource_conversion_factor);
    }

    #[Test]
    public function conversion_requires_the_exact_norm_and_semantically_matching_resource_name(): void
    {
        $candidate = (object) [
            'price_resource_code' => '12.2.05.02-1001',
            'price_resource_name' => 'Плиты теплоизоляционные минераловатные',
            'price_unit' => 'м3',
            'base_price' => 10_000,
            'price_id' => 17,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];
        $unrelatedMaterial = clone $candidate;
        $unrelatedMaterial->price_resource_name = 'Плиты древесные декоративные';
        $selector = new ResidentialAbstractResourcePriceSelector;

        self::assertNull($selector->select('12-01-999-99', '12.2.05.02', [$candidate], [4]));
        self::assertNull($selector->select('12-01-013-07', '12.2.05.02', [$unrelatedMaterial], [4]));
    }

    #[Test]
    public function selects_reinforcement_steel_for_the_exact_monolithic_floor_norm_instead_of_embedded_parts(): void
    {
        $reinforcement = (object) [
            'price_resource_code' => '08.4.03.03-0004',
            'price_resource_name' => 'Сталь арматурная рифленая свариваемая, класс A500C, диаметр 12 мм',
            'price_unit' => 'т',
            'base_price' => 72_000,
            'price_id' => 21,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];
        $embeddedParts = (object) [
            'price_resource_code' => '08.4.01.02-0011',
            'price_resource_name' => 'Детали закладные и накладные',
            'price_unit' => 'т',
            'base_price' => 115_095,
            'price_id' => 22,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];
        $wrongDiameter = (object) [
            ...get_object_vars($reinforcement),
            'price_resource_code' => '08.4.03.03-0006',
            'price_resource_name' => 'Сталь арматурная рифленая свариваемая, класс A500C, диаметр 16 мм',
            'base_price' => 60_000,
            'price_id' => 23,
        ];

        $selection = (new ResidentialAbstractResourcePriceSelector)->select(
            '06-23-003-05',
            '08.4.01.02',
            [$embeddedParts, $wrongDiameter, $reinforcement],
            [4],
        );

        self::assertNotNull($selection);
        self::assertSame('08.4.03.03-0004', $selection['row']->price_resource_code);
        self::assertSame(72_000.0, $selection['row']->unit_price);
        self::assertSame('т', $selection['row']->price_unit);
        self::assertSame('fsnb_semantic_hard_attributes_median:v4', $selection['policy']);
        self::assertSame('monolithic_floor_reinforcement_steel_group:08.4.03.03', $selection['assumption']);
    }

    #[Test]
    public function reinforcement_conversion_is_restricted_to_the_verified_norm_and_semantic_resource(): void
    {
        $reinforcement = (object) [
            'price_resource_code' => '08.4.03.03-0004',
            'price_resource_name' => 'Сталь арматурная рифленая свариваемая, класс A500C, диаметр 12 мм',
            'price_unit' => 'т',
            'base_price' => 72_000,
            'price_id' => 21,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];
        $unrelated = clone $reinforcement;
        $unrelated->price_resource_name = 'Прокат стальной листовой оцинкованный';
        $selector = new ResidentialAbstractResourcePriceSelector;

        self::assertNull($selector->select('06-23-003-04', '08.4.01.02', [$reinforcement], [4]));
        self::assertNull($selector->select('06-23-003-05', '08.4.01.02', [$unrelated], [4]));
    }

    #[Test]
    public function toilet_norm_selects_a_water_connector_and_rejects_a_gas_connector(): void
    {
        $waterConnector = (object) [
            'price_resource_code' => '18.2.06.08-0013',
            'price_resource_name' => 'Подводки гибкие армированные резиновые, диаметр 15 мм, длина 500 мм',
            'price_unit' => '10 шт',
            'base_price' => 1_800,
            'price_id' => 24,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];
        $gasConnector = (object) [
            'price_resource_code' => '18.2.06.08-0025',
            'price_resource_name' => 'Подводка гибкая к газовым приборам, сильфонная, диаметр 15 мм, длина 1200 мм',
            'price_unit' => 'шт',
            'base_price' => 312,
            'price_id' => 25,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $selection = (new ResidentialAbstractResourcePriceSelector)->select(
            '17-01-003-01',
            '18.2.06.08',
            [$gasConnector, $waterConnector],
            [4],
        );

        self::assertNotNull($selection);
        self::assertSame('18.2.06.08-0013', $selection['row']->price_resource_code);
        self::assertSame(180.0, $selection['row']->unit_price);
        self::assertSame('шт', $selection['row']->price_unit);
        self::assertSame('toilet_flexible_water_connector_per_piece:0.1', $selection['assumption']);
    }

    #[Test]
    public function supports_only_verified_residential_resource_conversions(): void
    {
        $selector = new ResidentialAbstractResourcePriceSelector;
        $lintel = (object) [
            'price_resource_code' => '05.1.03.09-1001',
            'price_resource_name' => 'Перемычки железобетонные',
            'price_unit' => 'м3',
            'base_price' => 50_000,
            'price_id' => 18,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsbc',
        ];
        $tile = (object) [
            'price_resource_code' => '06.2.01.02-0041',
            'price_resource_name' => 'Плитка керамическая для внутренней облицовки стен, глазурованная, гладкая, цветная',
            'price_unit' => 'м2',
            'base_price' => 2_000,
            'price_id' => 19,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $lintelSelection = $selector->select('07-01-021-01', '05.1.03.09', [$lintel], [4]);
        $tileSelection = $selector->select('15-01-019-05', '06.2.05.04', [$tile], [4]);

        self::assertSame(2_000.0, $lintelSelection['row']->unit_price ?? null);
        self::assertSame('шт', $lintelSelection['row']->price_unit ?? null);
        self::assertSame(2_000.0, $tileSelection['row']->unit_price ?? null);
        self::assertSame('м2', $tileSelection['row']->price_unit ?? null);
        self::assertSame('fsnb_semantic_hard_attributes_median:v4', $tileSelection['policy'] ?? null);
        self::assertSame('interior_ceramic_wall_tile_group:06.2.01.02', $tileSelection['assumption'] ?? null);
        self::assertContains([
            'group_code' => '06.2.05.04',
            'candidate_group_code' => '06.2.01.02',
            'from_unit' => 'м2',
        ], $selector->supportedCandidateGroups());
        self::assertContains([
            'group_code' => '08.4.01.02',
            'candidate_group_code' => '08.4.03.03',
            'from_unit' => 'т',
        ], $selector->supportedCandidateGroups());
    }

    #[Test]
    public function refuses_unknown_groups_or_unapproved_dataset_prices(): void
    {
        $candidate = (object) [
            'price_resource_code' => '12.2.05.02-1001',
            'price_unit' => 'м3',
            'base_price' => 10_000,
            'price_id' => 17,
            'dataset_version_id' => 9,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $selector = new ResidentialAbstractResourcePriceSelector;

        self::assertNull($selector->select('12-01-013-07', '12.2.05.02', [$candidate], [4]));
        self::assertNull($selector->select('12-01-013-07', '99.9.99.99', [$candidate], [9]));
    }
}
