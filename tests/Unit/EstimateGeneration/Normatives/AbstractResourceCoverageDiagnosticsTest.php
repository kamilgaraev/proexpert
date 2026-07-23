<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceCoverageDiagnostics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractResourceCoverageDiagnosticsTest extends TestCase
{
    #[Test]
    public function it_explains_why_an_abstract_resource_has_no_eligible_catalog_option(): void
    {
        $diagnostics = new AbstractResourceCoverageDiagnostics;

        $result = $diagnostics->build(
            [[
                'norm_code' => '16-02-002-01',
                'norm_name' => 'Прокладка трубопроводов водоснабжения',
                'group_code' => '18.2.07.01',
                'group_name' => 'Узлы трубопроводов укрупненные монтажные',
                'required_unit' => 'м',
                'required_quantity' => 99.8,
            ]],
            [
                (object) [
                    'group_code' => '18.2.07.01',
                    'unit' => 'т',
                    'dataset_version_id' => 501,
                    'regional_price_version_id' => null,
                    'region_id' => null,
                    'price_zone_id' => null,
                    'period_id' => null,
                    'source_type' => 'fsnb_2022',
                    'dataset_version' => '2026-05-07',
                ],
                (object) [
                    'group_code' => '18.2.07.01',
                    'unit' => 'м',
                    'dataset_version_id' => 700,
                    'regional_price_version_id' => 91,
                    'region_id' => 16,
                    'price_zone_id' => 3,
                    'period_id' => 8,
                    'source_type' => 'fgis_building_resources',
                    'dataset_version' => '2026-q2-ru-ta',
                ],
            ],
            regionalPriceVersionId: 91,
            regionId: 16,
            priceZoneId: 3,
            periodId: 8,
            baseDatasetIds: [501],
        );

        self::assertSame('18.2.07.01', $result[0]['group_code']);
        self::assertSame('м', $result[0]['required_unit']);
        self::assertSame(['м', 'т'], $result[0]['available_units']);
        self::assertSame(2, $result[0]['catalog_options_count']);
        self::assertSame(1, $result[0]['active_regional_options_count']);
        self::assertSame(1, $result[0]['approved_base_options_count']);
        self::assertSame(1, $result[0]['compatible_active_options_count']);
        self::assertSame(['fgis_building_resources', 'fsnb_2022'], $result[0]['source_types']);
    }
}
