<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Support\AI\PreparedTokenBudget;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use PHPUnit\Framework\TestCase;

final class PreparedTokenBudgetTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_reuse_counts_once_and_rejects_changed_or_untrusted_payloads(): void
    {
        $counts = (object) ['calls' => 0];
        $budget = new TokenBudgetService(new TokenCounter(new class($counts) {
            public function __construct(private \stdClass $counts) {}
            public function encode(string $text): array { $this->counts->calls++; return array_fill(0, mb_strlen($text), 1); }
        }));
        $messages = [['role' => 'user', 'content' => 'Проверь проект']];
        $tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]];
        $limits = ['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2];
        foreach ([1, 4, 6] as $calls) {
            $counts->calls = 0;
            for ($call = 0; $call < $calls; $call++) {
                $first = $budget->prepare($messages, $tools, 'short', $limits);
                $budget->prepare($first['messages'], $tools, 'short', $limits);
            }
            self::assertSame($calls * 4, $counts->calls);
            $counts->calls = 0;
            for ($call = 0; $call < $calls; $call++) {
                $prepared = PreparedTokenBudget::create($budget, $messages, $tools, 'short', $limits);
                self::assertSame($prepared->prepared, $budget->resolvePrepared($prepared, $messages, $tools, 'short', $limits));
            }
            self::assertSame($calls * 2, $counts->calls);
        }
        $prepared = PreparedTokenBudget::create($budget, $messages, $tools, 'short', $limits);
        foreach ([[$prepared->prepared, $messages, $tools, 'short', $limits],
            [$prepared, [['role' => 'user', 'content' => 'Другой вопрос']], $tools, 'short', $limits],
            [$prepared, $messages, [], 'short', $limits],
            [$prepared, $messages, $tools, 'normal', $limits],
            [$prepared, $messages, $tools, 'short', array_replace($limits, ['output_tokens' => 512])]] as $arguments) {
            $before = $counts->calls;
            $result = $budget->resolvePrepared(...$arguments);
            self::assertGreaterThan($before, $counts->calls);
            self::assertLessThanOrEqual($arguments[4]['output_tokens'], $result['max_completion_tokens']);
        }
    }

    public function test_native_preparation_reuse_binds_exact_call_output_items_and_flat_tools(): void
    {
        $budget = new TokenBudgetService(new TokenCounter(new class {
            public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
        }));
        $items = [['role' => 'user', 'content' => 'Запрос'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'lookup', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"count":2}']];
        $tools = [['type' => 'function', 'name' => 'lookup', 'parameters' => ['type' => 'object'], 'strict' => false]];
        $prepared = PreparedTokenBudget::create($budget, $items, $tools, 'normal', null);
        self::assertSame($prepared->prepared, $budget->resolvePrepared($prepared, $items, $tools));
        $items[2]['output'] = '{"count":3}';
        $fresh = $budget->resolvePrepared($prepared, $items, $tools);
        self::assertSame($items, $fresh['messages']);
        self::assertNotSame($prepared->prepared['messages'], $fresh['messages']);
    }

    public function test_reports_warmed_cpu_measurements_without_provider_calls(): void
    {
        $budget = new TokenBudgetService;
        $messages = [['role' => 'system', 'content' => str_repeat('Соблюдай права организации. ', 30)],
            ['role' => 'user', 'content' => str_repeat('Проверь сроки и суммы проекта. ', 40)]];
        $tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]];
        $budget->prepare($messages, $tools);
        $measurements = [];
        foreach ([1, 4, 6] as $calls) {
            foreach (['before', 'after'] as $mode) {
                $samples = [];
                $cpuSamples = [];
                for ($sample = 0; $sample < 5; $sample++) {
                    $start = hrtime(true);
                    $cpuStart = getrusage();
                    for ($call = 0; $call < $calls * 40; $call++) {
                        if ($mode === 'before') {
                            $first = $budget->prepare($messages, $tools);
                            $budget->prepare($first['messages'], $tools);
                        } else {
                            $prepared = PreparedTokenBudget::create($budget, $messages, $tools, 'normal', null);
                            $budget->resolvePrepared($prepared, $messages, $tools);
                        }
                    }
                    $cpuEnd = getrusage();
                    $samples[] = (hrtime(true) - $start) / 1_000_000 / 40;
                    $cpuSamples[] = (($cpuEnd['ru_utime.tv_sec'] + $cpuEnd['ru_stime.tv_sec'] - $cpuStart['ru_utime.tv_sec'] - $cpuStart['ru_stime.tv_sec']) * 1000
                        + ($cpuEnd['ru_utime.tv_usec'] + $cpuEnd['ru_stime.tv_usec'] - $cpuStart['ru_utime.tv_usec'] - $cpuStart['ru_stime.tv_usec']) / 1000) / 40;
                }
                sort($samples);
                sort($cpuSamples);
                $measurements[$calls][$mode.'_elapsed_median_ms'] = round($samples[2], 3);
                $measurements[$calls][$mode.'_cpu_median_ms'] = round($cpuSamples[2], 3);
                $measurements[$calls][$mode.'_count_passes'] = $calls * ($mode === 'before' ? 2 : 1);
            }
        }
        fwrite(STDOUT, "\nprepared_token_budget=".json_encode($measurements, JSON_THROW_ON_ERROR)."\n");
        self::assertCount(3, $measurements);
    }
}
