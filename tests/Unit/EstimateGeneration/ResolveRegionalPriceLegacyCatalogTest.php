<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use PHPUnit\Framework\TestCase;

final class ResolveRegionalPriceLegacyCatalogTest extends TestCase
{
    public function test_builds_traceable_snapshot_from_normative_rate_base_price(): void
    {
        $snapshot = (new ResolveRegionalPrice)->handle([
            'quantity' => 2.5,
            'normative_ref' => [
                'embedded_price' => [
                    'source_type' => 'normative_rate_base',
                    'normative_rate_id' => 42,
                    'normative_rate_resource_id' => 501,
                    'base_amount' => 850,
                    'currency' => 'RUB',
                    'base_year' => 2022,
                ],
            ],
        ], [
            'region_id' => 16,
            'price_zone_id' => 1,
            'period_id' => 202207,
        ]);

        self::assertSame('normative_rate_base', $snapshot->sourceType);
        self::assertSame('normative_rate_resources:501', $snapshot->sourceReference);
        self::assertSame('850.0000', $snapshot->baseAmount);
        self::assertSame('2125.00', $snapshot->finalAmount);
        self::assertSame(42, $snapshot->versionId);
    }
}
