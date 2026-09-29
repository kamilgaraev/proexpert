<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AIUsageTrackerTest extends TestCase
{
    public function test_calculates_luna_cost_with_gateway_prices(): void
    {
        config()->set('ai-assistant.llm.timeweb.input_price_per_million', null);
        config()->set('ai-assistant.llm.timeweb.output_price_per_million', null);

        $prices = (new UsageTracker)->calculateCostBreakdown(
            totalTokens: 2_000_000,
            model: 'openai/gpt-6-luna',
            inputTokens: 1_000_000,
            outputTokens: 1_000_000,
            providerName: 'timeweb'
        );

        $this->assertSame(['input' => 13.5, 'output' => 67.5, 'total' => 81.0], $prices);
    }

    public function test_calculates_luna_cost_with_openai_prices(): void
    {
        $prices = (new UsageTracker)->calculateCostBreakdown(
            totalTokens: 20_000,
            model: 'gpt-6-luna',
            inputTokens: 10_000,
            outputTokens: 10_000,
            providerName: 'openai'
        );

        $this->assertSame(['input' => 0.135, 'output' => 0.675, 'total' => 0.81], $prices);
    }

    public function test_calculates_timeweb_embedding_cost_from_input_tokens(): void
    {
        $tracker = new UsageTracker;

        $this->assertSame(
            130.5,
            round($tracker->calculateCost(
                totalTokens: 2_900_000,
                model: 'openai/text-embedding-3-large',
                inputTokens: 2_900_000,
                outputTokens: 0,
                providerName: 'timeweb'
            ), 2)
        );
    }

    public function test_calculates_timeweb_chat_cost_with_input_and_output_prices(): void
    {
        $tracker = new UsageTracker;

        $this->assertSame(
            2.37,
            round($tracker->calculateCost(
                totalTokens: 20_000,
                model: 'gemini/gemini-3.1-flash-lite',
                inputTokens: 10_000,
                outputTokens: 10_000,
                providerName: 'timeweb'
            ), 2)
        );
    }

    public function test_missing_embedding_usage_is_journaled_as_unavailable_without_estimated_final_cost(): void
    {
        $record = (new UsageTracker)->recordUsage(null, null, 'timeweb', 'openai/text-embedding-3-large', 'rag_index',
            999, 0, 999, ['usage_source' => 'unavailable', 'provider_usage_available' => false, 'estimated_input_tokens' => 999]);
        $this->assertNotNull($record);
        $record->refresh();
        $this->assertSame(0, $record->input_tokens);
        $this->assertSame(0, $record->total_tokens);
        $this->assertSame(0.0, (float) $record->total_cost_rub);
        $this->assertFalse($record->metadata['provider_usage_available']);
        $this->assertFalse($record->metadata['cost_available']);
        $this->assertFalse($record->metadata['cost_is_estimate']);
        $this->assertSame(999, $record->metadata['estimated_input_tokens']);
    }

    public function test_calculates_knowledge_qwen_cost_with_actual_gateway_identifier(): void
    {
        $prices = (new UsageTracker)->calculateCostBreakdown(
            totalTokens: 2_000_000,
            model: 'dashscope/qwen3.5-flash',
            inputTokens: 1_000_000,
            outputTokens: 1_000_000,
            providerName: 'timeweb'
        );

        $this->assertSame(['input' => 14.0, 'output' => 52.0, 'total' => 66.0], $prices);
    }

    public function test_monthly_usage_casts_cached_string_counter_to_integer(): void
    {
        $organizationId = 123456;
        $cacheKey = "ai_usage:{$organizationId}:".now()->year.':'.now()->month;

        Cache::put($cacheKey, '7', 600);

        $this->assertSame(7, (new UsageTracker)->getMonthlyUsage($organizationId));
    }
}
