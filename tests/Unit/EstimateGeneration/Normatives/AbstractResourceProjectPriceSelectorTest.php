<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceProjectPriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
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
}
