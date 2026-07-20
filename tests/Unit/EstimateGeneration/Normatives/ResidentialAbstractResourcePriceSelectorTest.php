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
    public function supports_only_the_verified_lintel_and_tile_conversions(): void
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
        self::assertSame('interior_ceramic_wall_tile_group:06.2.01.02', $tileSelection['assumption'] ?? null);
        self::assertContains([
            'group_code' => '06.2.05.04',
            'candidate_group_code' => '06.2.01.02',
            'from_unit' => 'м2',
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
