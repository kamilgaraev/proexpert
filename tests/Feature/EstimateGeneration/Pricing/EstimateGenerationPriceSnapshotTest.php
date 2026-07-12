<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationPriceSnapshotTest extends TestCase
{
    #[Test]
    public function persisted_snapshot_and_generated_total_do_not_follow_catalog_changes(): void
    {
        $catalog = ['amount' => '100.0000'];
        $resolver = new ResolveRegionalPrice(static function (int $priceId) use (&$catalog): array {
            return [
                'id' => $priceId,
                'region_id' => 16,
                'price_zone_id' => 3,
                'period_id' => 8,
                'regional_price_version_id' => 11,
                'base_price' => $catalog['amount'],
                'source_type' => 'fgiscs',
            ];
        });
        $service = new EstimatePricingService($resolver);
        $context = ['region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8, 'estimate_regional_price_version_id' => 11];
        $priced = $service->price([$this->workItem()], $context)[0];
        $item = new EstimateGenerationPackageItem(['price_snapshot' => $priced['price_snapshot'], 'total_cost' => $priced['total_cost']]);

        $catalog['amount'] = '900.0000';

        self::assertSame('250.00', $item->price_snapshot['base_amount']);
        self::assertSame('295.00', $item->price_snapshot['final_amount']);
        self::assertSame('295.00', $item->total_cost);
    }

    #[Test]
    public function regional_context_change_invalidates_price_evidence_and_priced_draft_state(): void
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
        $workItem = $this->workItem();
        $workItem['price_snapshot'] = ['region_id' => 16, 'version_id' => 11];

        $priced = (new EstimatePricingService($resolver))->price([$workItem], [
            'region_id' => 77,
            'price_zone_id' => 3,
            'period_id' => 8,
            'estimate_regional_price_version_id' => 11,
        ])[0];

        self::assertNull($priced['price_snapshot']);
        self::assertSame(0, $priced['total_cost']);
        self::assertSame('missing_price_snapshot', $priced['pricing_blocker']);
        self::assertContains('missing_price_snapshot', $priced['validation_flags']);
    }

    #[Test]
    public function incomplete_regional_context_fails_closed_for_priced_item(): void
    {
        $priced = (new EstimatePricingService(new ResolveRegionalPrice(static fn (): array => [])))
            ->price([$this->workItem()], [])[0];

        self::assertNull($priced['price_snapshot']);
        self::assertSame(0, $priced['total_cost']);
        self::assertNull($priced['price_source']);
        self::assertSame('missing_price_snapshot', $priced['pricing_blocker']);
    }

    private function workItem(): array
    {
        return [
            'item_type' => 'priced_work',
            'materials' => [['price_id' => 42, 'quantity' => 2.5, 'unit_price' => 100.0, 'total_price' => 250.0]],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'validation_flags' => [],
        ];
    }
}
