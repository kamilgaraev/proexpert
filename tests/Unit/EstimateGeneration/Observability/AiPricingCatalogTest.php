<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiPricingCatalogTest extends TestCase
{
    #[Test]
    public function it_selects_the_latest_effective_version_for_an_exact_operation_and_model(): void
    {
        $catalog = new AiPricingCatalog([
            'vision' => ['timeweb' => ['timeweb/vision-v2' => [
                ['version' => '2026-01', 'effective_at' => '2026-01-01T00:00:00+00:00', 'currency' => 'RUB', 'input_per_million' => '10.00', 'cached_input_per_million' => '5.00', 'output_per_million' => '20.00'],
                ['version' => '2026-07', 'effective_at' => '2026-07-01T00:00:00+00:00', 'currency' => 'RUB', 'input_per_million' => '12.00', 'cached_input_per_million' => '6.00', 'output_per_million' => '24.00'],
            ]]],
        ]);

        $snapshot = $catalog->resolve('vision', 'timeweb', 'timeweb/vision-v2', new DateTimeImmutable('2026-07-14T00:00:00+00:00'));

        self::assertSame('2026-07', $snapshot->version);
        self::assertSame('RUB', $snapshot->currency);
    }

    #[Test]
    public function unknown_prices_fail_closed(): void
    {
        $this->expectException(DomainException::class);
        (new AiPricingCatalog([]))->resolve('ocr', 'timeweb', 'unknown/model', new DateTimeImmutable);
    }

    #[Test]
    public function configured_reranker_fallback_has_a_versioned_budget_price(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 4).'/config/estimate-generation.php');

        self::assertIsString($configuration);
        self::assertStringContainsString("'openai/gpt-5-nano' => [[", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_INPUT_PER_MILLION', '7'", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_CACHED_INPUT_PER_MILLION', '7'", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_OUTPUT_PER_MILLION', '54'", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_CURRENCY', 'RUB'", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_VERSION', 'timeweb-ai-gateway-2026-07-20'", $configuration);
        self::assertStringContainsString("'ESTIMATE_GENERATION_RERANK_NANO_PRICE_EFFECTIVE_AT', '2026-07-20T00:00:00+00:00'", $configuration);
    }

    #[Test]
    public function luna_reasoning_is_billed_once_inside_completion_at_exact_decimal_rates(): void
    {
        $snapshot = AiPriceSnapshot::fromArray([
            'input_per_million' => '135',
            'cached_input_per_million' => '135',
            'output_per_million' => '810',
            'reasoning_per_million' => '810',
            'reasoning_mode' => 'included_in_output',
            'image_unit' => '0',
            'currency' => 'RUB',
            'source' => 'contract',
            'version' => 'timeweb-2026-08-13',
            'effective_at' => '2026-08-13T00:00:00+00:00',
        ]);

        $cost = (new AiCostCalculator)->calculate(
            10_462,
            0,
            4_081,
            3_928,
            1,
            0,
            $snapshot->toArray(),
        );

        self::assertSame('4.71798000', $cost->amount);
        self::assertSame('RUB', $cost->currency);
    }

    #[Test]
    public function current_luna_catalog_preserves_historical_rates_and_selects_the_release_price_without_mutating_captured_snapshots(): void
    {
        $previousContainer = \Illuminate\Container\Container::getInstance();
        $root = dirname(__DIR__, 4);
        try {
            new \Illuminate\Foundation\Application($root);
            $configuration = require $root.'/config/estimate-generation.php';
        } finally {
            \Illuminate\Container\Container::setInstance($previousContainer);
        }
        $catalog = new AiPricingCatalog($configuration['ai_pricing_catalog']);
        $operations = ['vision', 'ocr', 'rerank', 'project_synthesis', 'estimate_composition', 'estimate_audit', 'completeness_review'];
        self::assertEqualsCanonicalizing($operations, array_keys($configuration['ai_pricing_catalog']));
        foreach ($operations as $operation) {
            $before = $catalog->resolve($operation, 'timeweb', 'openai/gpt-6-luna', new DateTimeImmutable('2026-09-28T23:59:59+00:00'));
            $captured = $before->toArray();
            $current = $catalog->resolve($operation, 'timeweb', 'openai/gpt-6-luna', new DateTimeImmutable('2026-09-29T00:00:00+00:00'));
            self::assertSame('timeweb-ai-gateway-2026-09-26', $before->version, $operation);
            self::assertSame('2026-09-26T00:00:00+00:00', $before->effectiveAt);
            self::assertSame('timeweb-ai-gateway-2026-09-29-v2', $current->version, $operation);
            self::assertSame('2026-09-29T00:00:00+00:00', $current->effectiveAt);
            foreach (['input_per_million' => ['14', '13.5'], 'cached_input_per_million' => ['14', '13.5'],
                'output_per_million' => ['68', '67.5'], 'reasoning_per_million' => ['68', '67.5']] as $field => [$historicalRate, $releaseRate]) {
                self::assertSame($historicalRate, $captured[$field], $operation.'.'.$field);
                self::assertSame($releaseRate, $current->toArray()[$field], $operation.'.'.$field);
            }
            foreach ([$before, $current] as $snapshot) {
                self::assertSame('included_in_output', $snapshot->toArray()['reasoning_mode']);
                self::assertSame('RUB', $snapshot->currency);
                self::assertSame('contract', $snapshot->source);
                self::assertSame('0', $snapshot->toArray()['image_unit']);
                self::assertSame('0', $snapshot->toArray()['page_unit']);
            }
            $calculator = new AiCostCalculator;
            self::assertSame('81.00000000', $calculator->calculate(1_000_000, 500_000, 1_000_000, 250_000, 1, 1, $current->toArray())->amount);
            self::assertSame($captured, $before->toArray());
            $storedHistorical = AiPriceSnapshot::fromArray($captured);
            self::assertSame($captured, $storedHistorical->toArray());
            self::assertSame('82.00000000', $calculator->calculate(1_000_000, 500_000, 1_000_000, 250_000, 1, 1, $storedHistorical->toArray())->amount);
            self::assertSame($captured, $catalog->resolve($operation, 'timeweb', 'openai/gpt-6-luna', new DateTimeImmutable('2026-09-28T23:59:59+00:00'))->toArray());
        }
    }
}
