<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

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
