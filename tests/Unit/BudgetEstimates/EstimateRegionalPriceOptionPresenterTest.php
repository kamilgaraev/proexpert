<?php

declare(strict_types=1);

namespace Tests\Unit\BudgetEstimates;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Features\BudgetEstimates\Support\EstimateRegionalPriceOptionPresenter;
use PHPUnit\Framework\TestCase;

class EstimateRegionalPriceOptionPresenterTest extends TestCase
{
    public function test_period_name_is_built_from_structured_quarter_and_year(): void
    {
        $version = new EstimateRegionalPriceVersion();
        $version->setRawAttributes([
            'id' => 150,
            'source' => 'fgiscs',
            'version_key' => '2026-q3-ru-ta-r1',
            'status' => RegionalPriceStatus::ACTIVE->value,
            'region_id' => 16,
            'price_zone_id' => 202,
            'period_id' => 426,
            'activated_at' => null,
        ], true);

        $period = new EstimatePricePeriod();
        $period->setRawAttributes([
            'name' => '2 квартал 2026 г.',
            'year' => 2026,
            'quarter' => 3,
        ], true);
        $version->setRelation('period', $period);

        $option = (new EstimateRegionalPriceOptionPresenter())->version($version, [150]);

        self::assertSame('3 квартал 2026 г.', $option['period_name']);
        self::assertSame(2026, $option['year']);
        self::assertSame(3, $option['quarter']);
        self::assertTrue($option['is_active']);
    }
}
