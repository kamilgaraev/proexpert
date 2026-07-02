<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AIUsageTrackerTest extends TestCase
{
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

    public function test_monthly_usage_casts_cached_string_counter_to_integer(): void
    {
        $organizationId = 123456;
        $cacheKey = "ai_usage:{$organizationId}:".now()->year.':'.now()->month;

        Cache::put($cacheKey, '7', 600);

        $this->assertSame(7, (new UsageTracker)->getMonthlyUsage($organizationId));
    }
}
