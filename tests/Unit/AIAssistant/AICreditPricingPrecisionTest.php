<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Services\Credits\AICreditService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class AICreditPricingPrecisionTest extends TestCase
{
    private Container $originalContainer;

    protected function setUp(): void
    {
        $this->originalContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([
            'ai-assistant-credits' => require dirname(__DIR__, 3).'/config/ai-assistant-credits.php',
            'ai-assistant' => ['rag' => ['embedding_model' => 'openai/text-embedding-3-large', 'embedding_input_price_per_million' => 45.0],
                'llm' => ['timeweb' => ['input_price_per_million' => 13.5, 'output_price_per_million' => 67.5]]],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public function test_full_short_call_uses_supplier_precision_below_one_unit_cost(): void
    {
        $cost = (new AICreditService)->costMicroRub(8192, 1024);
        self::assertSame(179712, $cost);
        self::assertLessThan(180000, $cost);
        self::assertGreaterThan(180000, intdiv(8192 * 14000000 + 1024 * 68000000, 1000000));
        self::assertSame(14, (new AICreditService)->costMicroRub(1, 0));
        self::assertSame(81, (new AICreditService)->costMicroRub(1, 1));
        self::assertSame(2, config('ai-assistant-credits.price_version'));
    }

    public function test_luna_journal_cost_uses_precise_supplier_tariff(): void
    {
        $cost = (new UsageTracker)->calculateCostBreakdown(9216, 'openai/gpt-6-luna', 8192, 1024, 'timeweb');
        self::assertEqualsWithDelta(0.179712, $cost['total'], 0.000000000001);
    }

    public function test_embedding_price_is_independent_of_chat_price_overrides(): void
    {
        $cost = (new UsageTracker)->calculateCostBreakdown(1000000, 'openai/text-embedding-3-large', 1000000, 0, 'timeweb');
        self::assertSame(['input' => 45.0, 'output' => 0.0, 'total' => 45.0], $cost);
    }

    public function test_current_luna_overrides_do_not_change_historical_model_tariffs(): void
    {
        config(['ai-assistant.llm.timeweb.input_price_per_million' => 123.0,
            'ai-assistant.llm.timeweb.output_price_per_million' => 456.0]);
        $tracker = new UsageTracker;
        self::assertSame(['input' => 123.0, 'output' => 456.0, 'total' => 579.0],
            $tracker->calculateCostBreakdown(2000000, 'openai/gpt-6-luna', 1000000, 1000000, 'timeweb'));
        self::assertSame(['input' => 34.0, 'output' => 203.0, 'total' => 237.0],
            $tracker->calculateCostBreakdown(2000000, 'gemini/gemini-3.1-flash-lite', 1000000, 1000000, 'timeweb'));
        self::assertSame(['input' => 14.0, 'output' => 52.0, 'total' => 66.0],
            $tracker->calculateCostBreakdown(2000000, 'dashscope/qwen3.5-flash', 1000000, 1000000, 'timeweb'));
        self::assertSame(['input' => 45.0, 'output' => 0.0, 'total' => 45.0],
            $tracker->calculateCostBreakdown(1000000, 'openai/text-embedding-3-large', 1000000, 0, 'timeweb'));
    }
}
