<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceProjectPriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceSemanticPriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialSignedNormCompatibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractResourceProjectPriceSelectorTest extends TestCase
{
    #[Test]
    public function verified_residential_conversion_precedes_a_generic_same_group_price(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('roof.insulation', 'residential');
        self::assertIsArray($scenario);
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

        $selection = (new AbstractResourceProjectPriceSelector)->select(
            [[
                'object_type' => 'house',
                'specialization_scenario' => $scenario,
            ]],
            '12-01-013-07',
            'Утепление покрытий плитами из минеральной ваты',
            '12.2.05.02',
            'Плиты теплоизоляционные',
            11,
            [$candidate],
            [4],
        );

        self::assertSame('fsnb_2022_residential_converted_child_median:v1', $selection['policy'] ?? null);
        self::assertSame('м2', $selection['row']->price_unit ?? null);
        self::assertSame(2_000.0, $selection['row']->unit_price ?? null);
    }

    #[Test]
    public function incompatible_exact_group_tile_is_left_for_semantic_catalog_selection(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('sanitary.tile', 'residential');
        self::assertIsArray($scenario);
        $acidTile = (object) [
            'price_resource_code' => '06.2.05.04-0001',
            'price_resource_name' => 'Плитка кислотоупорная футеровочная графитовая',
            'price_unit' => 'т',
            'base_price' => 94_166.29,
            'unit_price' => 94_166.29,
            'price_id' => 7297,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsnb_2022',
        ];

        $selection = (new AbstractResourceProjectPriceSelector)->select(
            [['object_type' => 'house', 'specialization_scenario' => $scenario]],
            '15-01-019-05',
            'Гладкая облицовка стен керамическими плитками на клее',
            '06.2.05.04',
            'Плитки по проекту',
            11,
            [$acidTile],
            [4],
        );

        self::assertNull($selection);
    }

    #[Test]
    public function semantic_catalog_selects_ceramic_tile_and_rejects_graphite_tile(): void
    {
        $ceramic = (object) [
            'price_resource_code' => '06.2.01.02-0041',
            'price_resource_name' => 'Плитка керамическая глазурованная для стен',
            'price_unit' => 'м2',
            'base_price' => 1_298.47,
            'price_id' => 497603,
            'dataset_version_id' => 4,
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsbc',
        ];
        $graphite = (object) [
            ...get_object_vars($ceramic),
            'price_resource_code' => '06.2.05.04-0001',
            'price_resource_name' => 'Плитка кислотоупорная футеровочная графитовая',
            'price_id' => 7297,
        ];
        $selector = new AbstractResourceSemanticPriceSelector;

        self::assertSame('tile', $selector->queryHints(
            'Гладкая облицовка стен керамическими плитками на клее',
            'Плитки по проекту',
        )['family'] ?? null);
        $selection = $selector->select(
            'Гладкая облицовка стен керамическими плитками на клее',
            'Плитки по проекту',
            'м2',
            11,
            [$graphite, $ceramic],
            [4],
        );

        self::assertSame('06.2.01.02-0041', $selection['row']->price_resource_code ?? null);
    }

    #[Test]
    public function lintel_scenario_is_signed_for_its_exact_norm(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('walls.lintels', 'residential');

        self::assertIsArray($scenario);
        self::assertSame('07-01-021-01', $scenario['normative_rate_code'] ?? null);
        self::assertTrue((new ResidentialSignedNormCompatibility)->matches(
            $scenario,
            'house',
            '07-01-021-01',
            'Укладка перемычек при наибольшей массе монтажных элементов в здании: до 5 т, масса перемычки до 0,7 т',
        ));
    }
}
