<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Services\Credits\AICreditService;
use App\Support\AI\TokenBudgetService;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AssistantBudgetConfigurationTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_environment_limits_match_execution_and_credit_reservation_without_changing_existing_quotes(): void
    {
        $this->withEnvironment(['AI_ASSISTANT_NORMAL_INPUT_TOKENS' => '24576', 'AI_ASSISTANT_NORMAL_MAX_CALLS' => '5'], function (): void {
            $policy = require dirname(__DIR__, 3).'/config/ai-assistant-credits.php';
            config(['ai-assistant-credits' => $policy]);
            $messages = [['role' => 'user', 'content' => 'Найди цену бетона в любой смете']];
            $budget = new TokenBudgetService;
            $prepared = $budget->prepare($messages);

            self::assertSame(24576, $prepared['input_limit']);
            self::assertSame(5, $prepared['max_calls']);
            self::assertSame(2048, $prepared['max_completion_tokens']);
            self::assertEqualsWithDelta(2.35008, $prepared['max_cost_rub'], 0.000000001);
            $pricing = $policy['pricing'] + ['unit_cost_micro_rub' => 180000, 'unit_minor' => 100, 'charge_step_minor' => 50, 'minimum_minor' => 50];
            $maximum = new ReflectionMethod(AICreditService::class, 'maximumProfileUnitsMinor');
            self::assertSame(1350, $maximum->invoke(new AICreditService, $policy['profiles']['normal'], $pricing));

            $existing = $budget->prepare($messages, [], 'normal', ['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4]);
            self::assertSame(16384, $existing['input_limit']);
            self::assertSame(4, $existing['max_calls']);
            self::assertSame(800, $maximum->invoke(new AICreditService, $existing['budget_limits'], $pricing));
            self::assertSame(['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6], $policy['profiles']['ocr']);
        });
    }

    public function test_environment_cannot_remove_hard_limits_or_create_zero_call_profiles(): void
    {
        $this->withEnvironment(['AI_ASSISTANT_SHORT_INPUT_TOKENS' => '-1', 'AI_ASSISTANT_SHORT_MAX_CALLS' => '0',
            'AI_ASSISTANT_DETAILED_INPUT_TOKENS' => '999999', 'AI_ASSISTANT_DETAILED_MAX_CALLS' => '999'], function (): void {
            $policy = require dirname(__DIR__, 3).'/config/ai-assistant-credits.php';
            config(['ai-assistant-credits' => $policy]);
            self::assertSame(['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2], $policy['profiles']['short']);
            self::assertSame(['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6], $policy['profiles']['detailed']);
            self::assertSame(6, (new TokenBudgetService)->prepare([['role' => 'user', 'content' => 'Покажи сметы']], [], 'detailed')['max_calls']);
        });
    }

    private function withEnvironment(array $values, callable $assertions): void
    {
        $environment = Env::getRepository();
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = $environment->get($key);
            $environment->set($key, $value);
        }
        try {
            $assertions();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) { $environment->clear($key); }
                else { $environment->set($key, $value); }
            }
        }
    }
}
