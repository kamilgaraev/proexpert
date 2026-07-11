<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiCostCalculatorTest extends TestCase
{
    #[Test]
    public function it_prices_cached_tokens_separately_and_rounds_half_up(): void
    {
        $cost = (new AiCostCalculator)->calculate(
            inputTokens: 1_000,
            cachedInputTokens: 400,
            outputTokens: 200,
            reasoningTokens: 0,
            imageCount: 0,
            pageCount: 0,
            priceSnapshot: [
                'input_per_million' => '0.50',
                'cached_input_per_million' => '0.10',
                'output_per_million' => '2.00',
                'currency' => 'USD',
                'source' => 'fixture',
                'version' => 'v1',
                'effective_at' => '2026-07-11T00:00:00+00:00',
            ],
        );

        self::assertSame('0.00074000', $cost->amount);
        self::assertSame('USD', $cost->currency);
        self::assertSame('available', $cost->pricingStatus);
    }

    #[Test]
    public function it_prices_reasoning_images_and_pages_without_float_or_overflow(): void
    {
        $cost = (new AiCostCalculator)->calculate(
            inputTokens: PHP_INT_MAX,
            cachedInputTokens: PHP_INT_MAX,
            outputTokens: 10,
            reasoningTokens: 3,
            imageCount: 2,
            pageCount: 4,
            priceSnapshot: [
                'input_per_million' => '9.99',
                'cached_input_per_million' => '0.00',
                'output_per_million' => '1.00',
                'reasoning_per_million' => '2.00',
                'reasoning_mode' => 'excluded_from_output',
                'image_unit' => '0.125',
                'page_unit' => '0.01',
                'currency' => 'RUB',
                'source' => 'fixture',
                'version' => 'v2',
                'effective_at' => '2026-07-11T00:00:00+00:00',
            ],
        );

        self::assertSame('0.29001600', $cost->amount);
    }

    #[Test]
    public function it_does_not_double_charge_reasoning_included_in_reported_output(): void
    {
        $cost = (new AiCostCalculator)->calculate(0, 0, 100, 40, 0, 0, [
            'input_per_million' => '0',
            'cached_input_per_million' => '0',
            'output_per_million' => '1',
            'reasoning_per_million' => '2',
            'reasoning_mode' => 'included_in_output',
            'currency' => 'USD',
            'source' => 'fixture',
            'version' => 'v1',
            'effective_at' => '2026-07-11T00:00:00+00:00',
        ]);

        self::assertSame('0.00014000', $cost->amount);
    }

    #[Test]
    public function it_marks_missing_tariff_unavailable_instead_of_claiming_zero_cost(): void
    {
        $cost = (new AiCostCalculator)->calculate(1, 0, 0, 0, 0, 0, []);

        self::assertNull($cost->amount);
        self::assertNull($cost->currency);
        self::assertSame('unavailable', $cost->pricingStatus);
    }
}
