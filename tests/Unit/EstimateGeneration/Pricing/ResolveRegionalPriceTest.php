<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveUnitConversion;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolveRegionalPriceTest extends TestCase
{
    #[Test]
    public function price_from_another_region_is_never_used(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'region_id' => 16,
            'price_zone_id' => 3,
            'period_id' => 8,
            'regional_price_version_id' => 11,
            'base_price' => '100.0000',
            'source_type' => 'fgiscs',
        ]);

        $this->expectException(MissingRegionalPrice::class);
        $resolver->handle($this->resource(), $this->context(regionId: 77));
    }

    #[Test]
    public function exact_price_produces_complete_immutable_snapshot(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'region_id' => 16,
            'price_zone_id' => 3,
            'period_id' => 8,
            'regional_price_version_id' => 11,
            'base_price' => '100.0000',
            'source_type' => 'fgiscs',
        ]);

        $snapshot = $resolver->handle($this->resource(), $this->context());

        self::assertSame(16, $snapshot->regionId);
        self::assertSame(3, $snapshot->zoneId);
        self::assertSame(8, $snapshot->periodId);
        self::assertSame(11, $snapshot->versionId);
        self::assertSame('100.0000', $snapshot->baseAmount);
        self::assertSame('250.00', $snapshot->finalAmount);
        self::assertSame('RUB', $snapshot->currency);
    }

    #[Test]
    public function parsed_fsnb_base_price_is_allowed_without_regional_identity(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'dataset_version_id' => 6,
            'dataset_status' => 'parsed',
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'regional_price_version_id' => null,
            'base_price' => '100.0000',
            'source_type' => 'fsnb_2022',
        ]);

        $snapshot = $resolver->handle($this->resource(), $this->context());

        self::assertSame('fsnb_2022', $snapshot->sourceType);
        self::assertSame(11, $snapshot->versionId);
        self::assertSame('100.0000', $snapshot->baseAmount);
        self::assertSame('250.00', $snapshot->finalAmount);
        self::assertSame(6, $snapshot->coefficients['dataset_version_id']);
        self::assertSame('base_catalog', $snapshot->coefficients['price_kind']);
    }

    #[Test]
    public function verified_residential_abstract_resource_conversion_produces_a_catalog_grounded_snapshot(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-05-07',
            'dataset_status' => 'parsed',
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'regional_price_version_id' => null,
            'base_price' => '10000.0000',
            'source_type' => 'fsnb_2022',
        ]);

        $snapshot = $resolver->handle($this->convertedResource(), $this->context());

        self::assertSame('2000.0000', $snapshot->baseAmount);
        self::assertSame('4000.00', $snapshot->finalAmount);
        self::assertSame('base_catalog_converted', $snapshot->coefficients['price_kind']);
        self::assertSame('10000.0000', $snapshot->coefficients['source_unit_price']);
        self::assertSame('м3', $snapshot->coefficients['source_price_unit']);
        self::assertSame('0.20', $snapshot->coefficients['conversion_factor']);
        self::assertSame('mineral_wool_thickness_m:0.20', $snapshot->coefficients['conversion_assumption']);
    }

    #[Test]
    public function verified_residential_conversion_uses_the_active_regional_material_price(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-0006',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-q2-ru-ta-r1',
            'regional_price_version' => '2026-q2-ru-ta-r1',
            'regional_price_version_id' => 11,
            'region_id' => 16,
            'price_zone_id' => 3,
            'period_id' => 8,
            'base_price' => '7078.0000',
            'source_type' => 'fgiscs',
        ]);

        $snapshot = $resolver->handle($this->regionalConvertedResource(), $this->context());

        self::assertSame('1415.6000', $snapshot->baseAmount);
        self::assertSame('2831.20', $snapshot->finalAmount);
        self::assertSame('regional_catalog_converted', $snapshot->coefficients['price_kind']);
        self::assertSame('7078.0000', $snapshot->coefficients['source_unit_price']);
        self::assertSame('0.20', $snapshot->coefficients['conversion_factor']);
    }

    #[Test]
    public function residential_conversion_rejects_a_tampered_factor_even_when_the_price_id_exists(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-05-07',
            'dataset_status' => 'parsed',
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'regional_price_version_id' => null,
            'base_price' => '10000.0000',
            'source_type' => 'fsnb_2022',
        ]);
        $resource = $this->convertedResource();
        $resource['project_resource_selection']['conversion_factor'] = '0.01';
        $resource['normative_ref']['project_resource_selection']['conversion_factor'] = '0.01';

        $this->expectException(MissingRegionalPrice::class);
        $resolver->handle($resource, $this->context());
    }

    #[Test]
    public function residential_conversion_accepts_the_selector_six_decimal_rounding(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-05-07',
            'dataset_status' => 'parsed',
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'regional_price_version_id' => null,
            'base_price' => '12345.678901',
            'source_type' => 'fsnb_2022',
        ]);
        $resource = $this->convertedResource();
        $resource['unit_price'] = '2469.135780';
        $resource['project_resource_selection']['source_unit_price'] = '12345.678901';
        $resource['normative_ref']['project_resource_selection']['source_unit_price'] = '12345.678901';

        $snapshot = $resolver->handle($resource, $this->context());

        self::assertSame('2469.1358', $snapshot->baseAmount);
        self::assertSame('4938.27', $snapshot->finalAmount);
    }

    #[Test]
    public function residential_conversion_compares_reference_selection_by_catalog_identity_not_array_order(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-05-07',
            'dataset_status' => 'parsed',
            'regional_price_version_id' => null,
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'base_price' => '10000.0000',
            'source_type' => 'fsnb_2022',
        ]);
        $resource = $this->convertedResource();
        $reference = $resource['normative_ref']['project_resource_selection'];
        $resource['normative_ref']['project_resource_selection'] = [
            'policy' => $reference['policy'],
            'selected_resource_code' => $reference['selected_resource_code'],
            'group_code' => $reference['group_code'],
        ];

        $snapshot = $resolver->handle($resource, $this->context());

        self::assertSame('2000.0000', $snapshot->baseAmount);
    }

    #[Test]
    public function residential_conversion_accepts_an_eloquent_base_catalog_price(): void
    {
        $dataset = new EstimateDatasetVersion([
            'source_type' => 'fsnb_2022',
            'version_key' => '2026-05-07',
            'status' => 'parsed',
        ]);
        $dataset->setAttribute('id', 6);
        $price = new EstimateResourcePrice([
            'dataset_version_id' => 6,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'base_price' => '10000.0000',
        ]);
        $price->setAttribute('id', 42);
        $price->setRelation('datasetVersion', $dataset);
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): EstimateResourcePrice => $price);

        $snapshot = $resolver->handle($this->convertedResource(), $this->context());

        self::assertSame('2000.0000', $snapshot->baseAmount);
    }

    #[Test]
    public function base_price_from_unapproved_dataset_is_never_used(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'dataset_version_id' => 6,
            'dataset_status' => 'importing',
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'regional_price_version_id' => null,
            'base_price' => '100.0000',
            'source_type' => 'fsnb_2022',
        ]);

        $this->expectException(MissingRegionalPrice::class);
        $resolver->handle($this->resource(), $this->context());
    }

    #[Test]
    public function catalog_price_is_the_only_money_source(): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'regional_price_version_id' => 11, 'base_price' => '0.1000', 'source_type' => 'fgiscs',
        ]);

        $snapshot = $resolver->handle([
            'price_id' => 42, 'quantity' => '3', 'unit_price' => '999999.99', 'total_price' => '0.01',
        ], $this->context());

        self::assertSame('0.1000', $snapshot->baseAmount);
        self::assertSame('0.30', $snapshot->finalAmount);
    }

    #[Test]
    public function real_pricing_flow_resolves_exact_unit_conversion_and_applies_factor(): void
    {
        $prices = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'regional_price_version_id' => 11, 'base_price' => '60.0000', 'source_type' => 'fgiscs',
        ]);
        $conversions = new ResolveUnitConversion(static fn (string $from, string $to, int $version): array => [[
            'id' => 9, 'from_unit' => $from, 'to_unit' => $to, 'factor' => '0.016666666667',
            'version' => $version, 'fingerprint' => str_repeat('c', 64), 'is_active' => true,
        ]]);
        $item = [
            'item_type' => 'priced_work',
            'materials' => [],
            'labor' => [[
                'price_id' => 42, 'unit' => 'min', 'price_unit' => 'h', 'quantity' => '60',
                'normative_ref' => ['price_id' => 42, 'norm_resource_id' => 7],
            ]],
            'machinery' => [],
            'other_resources' => [],
        ];

        $priced = (new EstimatePricingService($prices, $conversions))->price([$item], $this->context())[0];

        self::assertSame('60.00', $priced['total_cost']);
        self::assertSame(9, $priced['labor'][0]['normative_ref']['unit_conversion_id']);
        self::assertSame('1.000000000020', $priced['labor'][0]['quantity']);
    }

    #[Test]
    public function real_pricing_flow_does_not_apply_a_second_conversion_to_a_residential_selected_resource(): void
    {
        $prices = new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId,
            'resource_code' => '12.2.05.02-1001',
            'unit' => 'м3',
            'dataset_version_id' => 6,
            'dataset_version' => '2026-05-07',
            'dataset_status' => 'parsed',
            'regional_price_version_id' => null,
            'region_id' => null,
            'price_zone_id' => null,
            'period_id' => null,
            'base_price' => '10000.0000',
            'source_type' => 'fsnb_2022',
        ]);
        $conversions = new ResolveUnitConversion(static fn (): array => []);
        $resource = $this->convertedResource();
        $resource['unit'] = 'm2';
        $resource['price_unit'] = 'м3';
        $selection = $resource['normative_ref']['project_resource_selection'];
        $staleSelection = [
            ...$selection,
            'policy' => 'fsnb_base_child_median:v1',
        ];
        $resource['project_resource_selection'] = $staleSelection;
        $resource['normative_ref']['project_resource_selection'] = $staleSelection;
        $item = [
            'item_type' => 'priced_work',
            'materials' => [$resource],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'normative_match' => [
                'project_resource_selections' => [[
                    ...$selection,
                    'price_id' => 42,
                    'applied_unit_price' => '2000',
                    'price_unit' => 'm2',
                ]],
            ],
        ];

        $priced = (new EstimatePricingService($prices, $conversions))->price([$item], $this->context())[0];

        self::assertSame('4000.00', $priced['total_cost']);
        self::assertSame('2000.0000', $priced['materials'][0]['unit_price']);
        self::assertSame('m2', $priced['materials'][0]['price_unit']);
        self::assertSame(
            'fsnb_2022_residential_converted_child_median:v1',
            $priced['materials'][0]['project_resource_selection']['policy'],
        );
        self::assertSame(
            '12.2.05.02-1001',
            $priced['materials'][0]['normative_ref']['project_resource_selection']['selected_resource_code'],
        );
        self::assertArrayNotHasKey('unit_conversion_id', $priced['materials'][0]['normative_ref']);
    }

    #[Test]
    public function missing_unit_conversion_preserves_the_rejected_unit_pair(): void
    {
        $resolver = new ResolveUnitConversion(static fn (): array => []);

        try {
            $resolver->handle('m2', 'м2', 3);
            self::fail('Missing unit conversion must fail closed.');
        } catch (MissingRegionalPrice $exception) {
            self::assertSame('unit_conversion_missing', $exception->reason);
            self::assertSame([
                'from_unit' => 'm2',
                'to_unit' => 'м2',
                'unit_conversion_version' => 3,
                'matching_rows_count' => 0,
            ], $exception->context);
        }
    }

    #[DataProvider('invalidPositiveIdentifiers')]
    #[Test]
    public function identifiers_must_be_positive_integers_or_canonical_digit_strings(mixed $invalid): void
    {
        $resolver = new ResolveRegionalPrice(static fn (int $priceId): never => throw new \RuntimeException('Invalid identifier reached lookup.'));

        $this->expectException(MissingRegionalPrice::class);
        $resolver->handle(['price_id' => $invalid, 'quantity' => '1'], $this->context());
    }

    public static function invalidPositiveIdentifiers(): array
    {
        return [
            'decimal' => ['1.5'],
            'exponent' => ['1e2'],
            'plus sign' => ['+1'],
            'leading zero' => ['01'],
            'zero' => [0],
            'overflow' => [(string) PHP_INT_MAX.'0'],
        ];
    }

    private function resource(): array
    {
        return ['price_id' => 42, 'quantity' => 2.5, 'unit_price' => 100.0, 'total_price' => 250.0];
    }

    private function convertedResource(): array
    {
        $selection = [
            'group_code' => '12.2.05.02',
            'selected_resource_code' => '12.2.05.02-1001',
            'selected_resource_name' => 'Плиты теплоизоляционные минераловатные',
            'price_source' => 'fsnb_base',
            'price_source_version' => '2026-05-07',
            'policy' => 'fsnb_2022_residential_converted_child_median:v1',
            'candidates_count' => 3,
            'conversion_assumption' => 'mineral_wool_thickness_m:0.20',
            'source_unit_price' => '10000',
            'source_price_unit' => 'м3',
            'conversion_factor' => '0.20',
        ];

        return [
            'price_id' => 42,
            'unit' => 'м2',
            'price_unit' => 'м2',
            'quantity' => '2',
            'unit_price' => '2000',
            'project_resource_selection' => $selection,
            'normative_ref' => [
                'norm_code' => '12-01-013-07',
                'resource_code' => '12.2.05.02',
                'price_id' => 42,
                'project_resource_selection' => $selection,
            ],
        ];
    }

    private function regionalConvertedResource(): array
    {
        $resource = $this->convertedResource();
        $selection = [
            ...$resource['project_resource_selection'],
            'selected_resource_code' => '12.2.05.02-0006',
            'price_source' => 'regional_catalog',
            'price_source_version' => '2026-q2-ru-ta-r1',
            'policy' => 'regional_residential_converted_child_median:v1',
            'source_unit_price' => '7078',
        ];

        $resource['price_unit'] = 'м2';
        $resource['unit_price'] = '1415.6';
        $resource['project_resource_selection'] = $selection;
        $resource['normative_ref']['project_resource_selection'] = $selection;

        return $resource;
    }

    private function context(int $regionId = 16): array
    {
        return [
            'region_id' => $regionId,
            'price_zone_id' => 3,
            'period_id' => 8,
            'estimate_regional_price_version_id' => 11,
        ];
    }
}
