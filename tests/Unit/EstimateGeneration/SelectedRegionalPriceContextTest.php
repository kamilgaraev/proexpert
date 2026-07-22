<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\SelectedRegionalPriceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectedRegionalPriceContextTest extends TestCase
{
    #[Test]
    public function selected_active_price_version_replaces_pricing_identity_and_preserves_normative_pin(): void
    {
        $resolver = $this->createMock(EstimateGenerationRegionalContextResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(['estimate_regional_price_version_id' => 150])
            ->willReturn([
                'estimate_regional_price_version_id' => 150,
                'region_id' => 16,
                'region_name' => 'Республика Татарстан',
                'price_zone_id' => 3,
                'period_id' => 12,
                'version_key' => '2026-q2-ru-ta-r1',
                'status' => 'active',
            ]);

        $input = (new SelectedRegionalPriceContext($resolver))->replace([
            'description' => 'Двухэтажный индивидуальный жилой дом',
            'regional_context' => [
                'normative_dataset_version' => 'ФСНБ-2022, редакция 2026-01',
                'normative_rerank_requested' => true,
            ],
        ], 150);

        self::assertSame(150, $input['estimate_regional_price_version_id']);
        self::assertSame(16, $input['region_id']);
        self::assertSame('Республика Татарстан', $input['region']);
        self::assertSame(3, $input['price_zone_id']);
        self::assertSame(12, $input['period_id']);
        self::assertSame('ФСНБ-2022, редакция 2026-01', $input['regional_context']['normative_dataset_version']);
        self::assertSame(150, $input['regional_context']['estimate_regional_price_version_id']);
        self::assertSame('2026-q2-ru-ta-r1', $input['regional_context']['version_key']);
    }

    #[Test]
    public function unresolved_price_version_is_rejected_instead_of_clearing_the_existing_context(): void
    {
        $resolver = $this->createMock(EstimateGenerationRegionalContextResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(['estimate_regional_price_version_id' => 150])
            ->willReturn([
                'estimate_regional_price_version_id' => null,
                'status' => 'regional_context_missing',
            ]);

        $this->expectException(\InvalidArgumentException::class);

        (new SelectedRegionalPriceContext($resolver))->replace([
            'regional_context' => ['normative_dataset_version' => 'ФСНБ-2022, редакция 2026-01'],
        ], 150);
    }
}
