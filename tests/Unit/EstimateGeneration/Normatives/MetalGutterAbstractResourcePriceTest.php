<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceSemanticPriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceRowData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetalGutterAbstractResourcePriceTest extends TestCase
{
    #[Test]
    public function metal_gutter_pipe_uses_only_regional_gutter_pipe_with_exact_unit(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Устройство металлической водосточной системы: прямых звеньев труб',
            'Труба водосточная',
            'м',
            11,
            [
                $this->price(1, '08.1.02.22-0001', 'Труба из оцинкованной стали для водосточных систем, диаметр 140 мм', 'м', '408.79'),
                $this->price(2, '12.1.01.05-0064', 'Труба металлическая для водосточных систем, длина 2000 мм', 'шт', '683.75'),
                $this->price(3, '12.1.01.04-0004', 'Желоб из ПВХ для восточных систем', 'м', '208.68'),
                $this->price(4, '73.9.44.08', 'Труба стальная водопроводная', 'м', '300.00'),
            ],
        );

        self::assertSame(1, $selection['row']->price_id ?? null);
        self::assertSame(1, $selection['candidates_count'] ?? null);
        self::assertSame('regional_semantic_metal_gutter_family_median:v1', $selection['policy'] ?? null);
    }

    #[Test]
    public function metal_gutter_fittings_use_lower_median_of_explicit_regional_fittings(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Устройство металлической водосточной системы: прямых звеньев труб',
            'Изделия для водосточных труб',
            'шт',
            11,
            [
                $this->price(10, '12.1.01.05-0053', 'Колено трубы из оцинкованной стали для водосточных систем', 'шт', '171.96'),
                $this->price(11, '12.1.01.05-0058', 'Соединитель желоба металлический для водосточных систем', 'шт', '274.75'),
                $this->price(12, '12.1.01.05-0060', 'Тройник трубы металлический для водосточных систем', 'шт', '1039.47'),
                $this->price(13, '12.1.01.05-0064', 'Труба металлическая для водосточных систем', 'шт', '683.75'),
                $this->price(14, '12.1.01.04-0007', 'Колено трубы из ПВХ для водосточных систем', 'шт', '217.09'),
            ],
        );

        self::assertSame(11, $selection['row']->price_id ?? null);
        self::assertSame(3, $selection['candidates_count'] ?? null);
    }

    #[Test]
    public function selected_metal_gutter_resource_preserves_exact_regional_price_source(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101,
            'norm_resource_id' => 7001,
            'construction_resource_id' => null,
            'price_construction_resource_id' => 502,
            'price_id' => 9001,
            'resource_type' => 'abstract',
            'resource_code' => '08.1.02.22',
            'price_resource_code' => '12.1.01.05-0058',
            'resource_name' => 'Изделия для водосточных труб',
            'price_resource_name' => 'Соединитель желоба металлический для водосточных систем',
            'unit' => 'шт',
            'price_unit' => 'шт',
            'quantity' => '2.000000',
            'unit_price' => '274.750000',
            'regional_price_version_id' => 11,
            'regional_price_version_key' => '2026-q2-ru-ta',
            'raw_source_tag' => 'AbstractResource',
            'project_resource_candidates_count' => 3,
            'project_resource_price_policy' => 'regional_semantic_metal_gutter_family_median:v1',
        ]);

        self::assertSame('regional_catalog', $mapped->resource['price_source']);
        self::assertSame('2026-q2-ru-ta', $mapped->resource['price_source_version']);
        self::assertSame('12.1.01.05-0058', $mapped->resource['project_resource_selection']['selected_resource_code']);
        self::assertSame('274.750000', $mapped->resource['unit_price']);
    }

    #[Test]
    public function production_source_bounds_cross_group_query_to_metal_gutter_family(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php');

        self::assertIsString($source);
        self::assertStringContainsString('($hint[\'family\'] ?? \'pipe\')', $source);
        self::assertStringContainsString("'%водосточ%'", $source);
        self::assertStringContainsString("'%металл%'", $source);
    }

    private function price(int $id, string $code, string $name, string $unit, string $price): object
    {
        return (object) [
            'price_id' => $id,
            'price_resource_code' => $code,
            'price_resource_name' => $name,
            'price_unit' => $unit,
            'base_price' => $price,
            'regional_price_version_id' => 11,
        ];
    }
}
