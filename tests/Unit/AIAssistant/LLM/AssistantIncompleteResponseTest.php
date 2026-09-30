<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantIncompleteResponseTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('providerFailures')]
    public function test_incomplete_blocks_return_and_records_actual_usage_once(bool $providerThrows, string $content): void
    {
        $response = ['content' => $content, 'finish_reason' => 'length', 'incomplete_reason' => 'max_output_tokens', 'input_tokens' => 80, 'output_tokens' => 128, 'tokens_used' => 208, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $invocation = $provider->expects($this->once())->method('chat');
        if ($providerThrows) {
            $invocation->willThrowException(new AssistantResponseIncomplete($response));
        } else {
            $invocation->willReturn($response);
        }
        $usage = $this->createMock(UsageTracker::class);
        $usage->expects($this->once())->method('recordUsage')->with(10, 1, 'timeweb', 'openai/gpt-6-luna', 'assistant_chat', 80, 128, 208, $this->callback(static fn (array $metadata): bool => $metadata['degraded_mode'] === true));
        $service = $this->service($provider, $usage);

        $this->expectException(AssistantResponseIncomplete::class);
        (new ReflectionMethod(AIAssistantService::class, 'requestAssistantResponse'))->invoke($service, [['role' => 'user', 'content' => 'Вопрос']], [], 10, (new User())->forceFill(['id' => 1]));
    }

    public static function providerFailures(): array
    {
        return [[true, 'Обрезанный ответ'], [false, 'Обрезанный ответ'], [true, ''], [false, '']];
    }

    public function test_completed_response_is_returned_unchanged_and_usage_is_recorded_once(): void
    {
        $response = ['content' => 'Полный ответ', 'finish_reason' => 'stop', 'input_tokens' => 80, 'output_tokens' => 20, 'tokens_used' => 100, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->willReturn($response);
        $usage = $this->createMock(UsageTracker::class);
        $usage->expects($this->once())->method('recordUsage')->with(10, 1, 'timeweb', 'openai/gpt-6-luna', 'assistant_chat', 80, 20, 100, $this->callback(static fn (array $metadata): bool => $metadata['degraded_mode'] === false));

        $result = (new ReflectionMethod(AIAssistantService::class, 'requestAssistantResponse'))->invoke($this->service($provider, $usage), [['role' => 'user', 'content' => 'Вопрос']], [], 10, (new User())->forceFill(['id' => 1]));
        self::assertSame($response, $result['response']);
    }

    private function service(LLMProviderInterface $provider, UsageTracker $usage): AIAssistantService
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $values = [
            'llmProvider' => $provider,
            'usageTracker' => $usage,
            'logging' => $this->createMock(LoggingService::class),
            'requestLifecycle' => null,
            'tokenBudget' => new TokenBudgetService(new TokenCounter(new class {
                public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
            })),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(AIAssistantService::class, $name))->setValue($service, $value);
        }

        return $service;
    }
}
