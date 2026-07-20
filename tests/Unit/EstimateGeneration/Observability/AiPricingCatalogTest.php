<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

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
}
