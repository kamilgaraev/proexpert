<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TokenBudgetServiceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_budget_preserves_system_instruction_and_current_query(): void
    {
        $budget = $this->budget()->build([
            ['role' => 'system', 'content' => str_repeat('s', 100)],
            ['role' => 'user', 'content' => str_repeat('h', 7000)],
            ['role' => 'assistant', 'content' => str_repeat('a', 7000)],
            ['role' => 'user', 'content' => 'текущий запрос'],
        ], [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]], 'short');

        self::assertSame('s', $budget['messages'][0]['content'][0]);
        self::assertSame('текущий запрос', $budget['messages'][array_key_last($budget['messages'])]['content']);
        self::assertLessThanOrEqual(8192, $budget['input_tokens']);
        self::assertSame(1024, $budget['max_completion_tokens']);
        self::assertSame(2, $budget['max_calls']);
        self::assertGreaterThan(0.0, $budget['estimated_cost_rub']);
    }

    public function test_current_query_above_reserve_is_preserved_in_full(): void
    {
        $query = str_repeat('q', 5000);
        $prepared = $this->budget()->prepare([
            ['role' => 'developer', 'content' => 'обязательные правила'],
            ['role' => 'user', 'content' => $query],
        ], [], 'short');
        self::assertSame($query, $prepared['messages'][1]['content']);
    }

    public function test_tool_call_and_results_from_old_history_are_removed_together(): void
    {
        $prepared = $this->budget()->prepare([
            ['role' => 'system', 'content' => 'правила'],
            ['role' => 'assistant', 'content' => str_repeat('a', 6000), 'tool_calls' => [['id' => 'old']]],
            ['role' => 'tool', 'tool_call_id' => 'old', 'content' => str_repeat('b', 6000)],
            ['role' => 'user', 'content' => 'текущий запрос'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'active']]],
            ['role' => 'tool', 'tool_call_id' => 'active', 'content' => 'актуальный результат'],
        ], [], 'short');
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($prepared['messages'], 'role'));
        self::assertSame('active', $prepared['messages'][3]['tool_call_id']);
    }

    public function test_active_tool_result_is_not_silently_dropped_to_expand_budget(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ai_token_budget_exhausted');
        $this->budget()->prepare([
            ['role' => 'user', 'content' => 'текущий запрос'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'active']]],
            ['role' => 'tool', 'tool_call_id' => 'active', 'content' => str_repeat('b', 9000)],
        ], [], 'short');
    }

    public function test_large_tool_schema_alone_exhausts_budget(): void
    {
        $this->expectException(DomainException::class);
        $this->budget()->prepare([['role' => 'user', 'content' => 'запрос']], [['description' => str_repeat('s', 9000)]], 'short');
    }

    public function test_profiles_bound_whole_task_cost_and_calibration_remains_estimate(): void
    {
        foreach (['short' => [8192, 1024, 2], 'normal' => [16384, 2048, 4], 'detailed' => [32768, 4096, 6]] as $profile => [$input, $output, $calls]) {
            $prepared = $this->budget()->prepare([['role' => 'user', 'content' => 'Сколько стоит проект?']], [], $profile);
            self::assertSame($output, $prepared['max_completion_tokens']);
            self::assertSame($calls, $prepared['max_calls']);
            self::assertEqualsWithDelta(($input * 13.5 + $output * 67.5) * $calls / 1000000, $prepared['max_cost_rub'], 0.000001);
            $calibration = $this->budget()->calibration($prepared, $prepared['raw_input_tokens'] * 2);
            self::assertFalse($calibration['exact']);
            self::assertEquals(2.0, $calibration['actual_to_estimate_ratio']);
        }
    }

    public function test_observed_usage_increases_conservative_input_reserve(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore);
        $budget = new TokenBudgetService($counter, $cache);
        $messages = [['role' => 'user', 'content' => 'запрос']];
        $before = $budget->prepare($messages);
        $budget->calibration($before, $before['raw_input_tokens'] * 2);
        $after = $budget->prepare($messages);
        self::assertEquals(2.2, $after['safety_factor']);
        self::assertGreaterThan($before['input_tokens'], $after['input_tokens']);
        $budget->calibration($after, $after['raw_input_tokens']);
        self::assertEquals(2.2, $budget->prepare($messages)['safety_factor']);
        $otherModel = new TokenBudgetService($counter, $cache, 'another-model-version');
        self::assertEquals(1.1, $otherModel->prepare($messages)['safety_factor']);
        $this->expectException(DomainException::class);
        $budget->prepare([['role' => 'user', 'content' => str_repeat('q', 4000)]], [], 'short');
    }

    public function test_cache_write_failure_preserves_paid_usage_and_marks_calibration_unavailable(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(1.1);
        $cache->method('forever')->willThrowException(new \RuntimeException('cache unavailable'));
        $budget = new TokenBudgetService(new TokenCounter, $cache);
        $prepared = $budget->prepare([['role' => 'user', 'content' => 'запрос']]);
        $calibration = $budget->calibration($prepared, 9000);
        self::assertFalse($calibration['persisted']);
        self::assertFalse($calibration['profile_input_exceeded']);
        self::assertSame(9000, $calibration['actual_input_tokens']);
    }

    public function test_quote_snapshot_retains_limits_after_profile_configuration_changes(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $app = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($app);
        $app->instance('config', new \Illuminate\Config\Repository([
            'ai-assistant-credits' => ['profiles' => ['short' => ['input_tokens' => 2000, 'output_tokens' => 400, 'max_calls' => 1]]],
        ]));
        try {
            $messages = [['role' => 'user', 'content' => str_repeat('q', 3500)]];
            $snapshot = ['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2];
            $prepared = $this->budget()->prepare($messages, [], 'short', $snapshot);
            self::assertSame($snapshot, $prepared['budget_limits']);
            self::assertSame($messages, $prepared['messages']);
            self::assertSame(1024, $prepared['max_completion_tokens']);
            $new = $this->budget()->prepare([['role' => 'user', 'content' => 'короткий запрос']], [], 'short');
            self::assertSame(2000, $new['input_limit']);
            self::assertSame(400, $new['max_completion_tokens']);
            self::assertSame(1, $new['max_calls']);
            self::assertSame(1024, TokenBudgetService::limits('short')['output']);
            $this->expectException(DomainException::class);
            $this->budget()->prepare($messages, [], 'short');
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }

    public function test_smaller_snapshot_counts_all_tools_and_calibrates_against_its_input_limit(): void
    {
        $tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => str_repeat('t', 80)]]];
        $prepared = $this->budget()->prepare([
            ['role' => 'system', 'content' => 'правила'],
            ['role' => 'user', 'content' => str_repeat('h', 1000)],
            ['role' => 'user', 'content' => 'текущий запрос'],
        ], $tools, 'short', ['input' => 512, 'output' => 111, 'calls' => 1]);
        self::assertSame($tools, $prepared['tools']);
        self::assertSame(512, $prepared['input_limit']);
        self::assertSame(111, $prepared['max_completion_tokens']);
        self::assertSame(1, $prepared['max_calls']);
        self::assertCount(2, $prepared['messages']);
        self::assertSame($prepared['message_tokens'] + $prepared['tools_tokens'], $prepared['raw_input_tokens']);
        self::assertLessThanOrEqual(512, $prepared['input_tokens']);
        self::assertTrue($this->budget()->calibration($prepared, 513)['profile_input_exceeded']);
        self::assertFalse($this->budget()->calibration($prepared, 511)['profile_input_exceeded']);
    }

    public function test_invalid_or_conflicting_reserved_limits_fail_closed(): void
    {
        foreach ([
            ['input_tokens' => 0, 'output_tokens' => 100, 'max_calls' => 1],
            ['input_tokens' => '512', 'output_tokens' => 100, 'max_calls' => 1],
            ['input_tokens' => 32769, 'output_tokens' => 100, 'max_calls' => 1],
            ['input_tokens' => 512, 'output_tokens' => 4097, 'max_calls' => 1],
            ['input_tokens' => 512, 'output_tokens' => 100, 'max_calls' => 7],
            ['input_tokens' => 512, 'output_tokens' => 100],
            ['input_tokens' => 512, 'input' => 513, 'output_tokens' => 100, 'max_calls' => 1],
        ] as $limits) {
            try {
                $this->budget()->prepare([['role' => 'user', 'content' => 'запрос']], [], 'short', $limits);
                self::fail('Invalid reserved limits must be rejected.');
            } catch (DomainException $exception) {
                self::assertSame('ai_token_limits_invalid', $exception->getMessage());
            }
        }
    }

    public function test_current_precise_short_pricing_stays_below_two_unit_ceiling(): void
    {
        $prepared = $this->budget()->prepare([['role' => 'user', 'content' => 'Проверь данные']], [], 'short');
        self::assertEqualsWithDelta(0.179712, $prepared['max_cost_rub'] / $prepared['max_calls'], 0.000000001);
        self::assertEqualsWithDelta(0.359424, $prepared['max_cost_rub'], 0.000000001);
        self::assertLessThan(0.36, $prepared['max_cost_rub']);
        self::assertSame(8192, $prepared['input_limit']);
        self::assertSame(1024, $prepared['max_completion_tokens']);
        self::assertSame(2, $prepared['max_calls']);
    }

    public function test_display_estimates_follow_current_configured_pricing_without_changing_limits(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $app = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($app);
        $configuration = new \Illuminate\Config\Repository(['ai-assistant-credits' => ['pricing' => [
            'input_micro_rub_per_million' => 13_500_000, 'output_micro_rub_per_million' => 67_500_000,
        ]]]);
        $app->instance('config', $configuration);
        try {
            $messages = [['role' => 'user', 'content' => 'Проверь данные']];
            $before = $this->budget()->prepare($messages, [], 'short');
            $configuration->set('ai-assistant-credits.pricing', ['input_micro_rub_per_million' => 27_000_000, 'output_micro_rub_per_million' => 135_000_000]);
            $after = $this->budget()->prepare($messages, [], 'short');
            self::assertEqualsWithDelta($before['estimated_cost_rub'] * 2, $after['estimated_cost_rub'], 0.000001);
            self::assertEqualsWithDelta(0.718848, $after['max_cost_rub'], 0.000000001);
            self::assertSame($before['budget_limits'], $after['budget_limits']);
            self::assertSame($messages, $after['messages']);
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }

    public function test_invalid_display_pricing_cannot_produce_a_misleading_estimate(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $app = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($app);
        $configuration = new \Illuminate\Config\Repository;
        $app->instance('config', $configuration);
        try {
            foreach ([[], ['input_micro_rub_per_million' => '13500000', 'output_micro_rub_per_million' => 67_500_000],
                ['input_micro_rub_per_million' => -1, 'output_micro_rub_per_million' => 67_500_000],
                ['input_micro_rub_per_million' => PHP_INT_MAX, 'output_micro_rub_per_million' => 67_500_000]] as $pricing) {
                $configuration->set('ai-assistant-credits.pricing', $pricing);
                try {
                    $this->budget()->prepare([['role' => 'user', 'content' => 'Проверь данные']], [], 'short');
                    self::fail('Invalid display pricing must be rejected.');
                } catch (DomainException $exception) {
                    self::assertSame('ai_token_pricing_invalid', $exception->getMessage());
                }
            }
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }

    private function budget(): TokenBudgetService
    {
        return new TokenBudgetService(new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        }));
    }
}
