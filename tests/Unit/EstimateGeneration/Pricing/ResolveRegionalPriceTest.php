<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
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
