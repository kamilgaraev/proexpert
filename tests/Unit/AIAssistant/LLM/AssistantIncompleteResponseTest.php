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
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
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
        (new ReflectionMethod(AIAssistantService::class, 'requestAssistantResponse'))->invoke($service, [['role' => 'user', 'content' => 'Вопрос']], [], 10, (new User)->forceFill(['id' => 1]));
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

        $result = (new ReflectionMethod(AIAssistantService::class, 'requestAssistantResponse'))->invoke($this->service($provider, $usage), [['role' => 'user', 'content' => 'Вопрос']], [], 10, (new User)->forceFill(['id' => 1]));
        self::assertSame($response, $result['response']);
    }

    #[DataProvider('telemetryOutcomes')]
    public function test_provider_timing_is_safe_and_never_changes_the_call_outcome(bool $fails, string $loggerMode): void
    {
        $response = ['content' => 'private_answer_fixture', 'finish_reason' => 'stop', 'input_tokens' => 80, 'output_tokens' => 20,
            'tokens_used' => 100, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna'];
        $failure = new RuntimeException('private_provider_error_fixture');
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $call = $provider->expects($this->once())->method('chat');
        $fails ? $call->willThrowException($failure) : $call->willReturn($response);
        $usage = $this->createMock(UsageTracker::class);
        $usage->expects($this->once())->method('recordUsage');
        $logger = new class($loggerMode === 'throw') extends \Psr\Log\AbstractLogger
        {
            public array $records = [];

            public function __construct(private readonly bool $throws) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'event' => (string) $message, 'context' => $context];
                if ($this->throws) {
                    throw new RuntimeException('controlled_logger_failure');
                }
            }
        };
        Log::clearResolvedInstance('log');
        if ($loggerMode === 'missing') {
            app()->offsetUnset('log');
        } else {
            Log::swap($logger);
        }

        try {
            $result = (new ReflectionMethod(AIAssistantService::class, 'requestAssistantResponse'))->invoke(
                $this->service($provider, $usage), [['role' => 'user', 'content' => 'private_question_fixture']], [], 10, (new User)->forceFill(['id' => 1]));
            $this->assertFalse($fails);
            $this->assertSame($response, $result['response']);
        } catch (RuntimeException $actual) {
            $this->assertTrue($fails);
            $this->assertSame($failure, $actual);
        }

        if ($loggerMode === 'missing') {
            $this->assertSame([], $logger->records);

            return;
        }
        $this->assertCount(1, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('ai.assistant.provider_call_completed', $logger->records[0]['event']);
        $context = $logger->records[0]['context'];
        $this->assertSame(['request_id', 'call_attempt', 'provider', 'model', 'duration_ms', 'success', 'exception_class'], array_keys($context));
        $this->assertNull($context['request_id']);
        $this->assertSame(1, $context['call_attempt']);
        $this->assertSame('timeweb', $context['provider']);
        $this->assertSame('openai/gpt-6-luna', $context['model']);
        $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
        $this->assertSame(! $fails, $context['success']);
        $this->assertSame($fails ? RuntimeException::class : null, $context['exception_class']);
        $this->assertStringNotContainsString('private_', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public static function telemetryOutcomes(): array
    {
        return ['success' => [false, 'record'], 'failure' => [true, 'record'], 'logger_failure_after_success' => [false, 'throw'],
            'logger_failure_after_provider_failure' => [true, 'throw'], 'unavailable_logger' => [false, 'missing']];
    }

    private function service(LLMProviderInterface $provider, UsageTracker $usage): AIAssistantService
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $values = [
            'llmProvider' => $provider,
            'usageTracker' => $usage,
            'logging' => $this->createMock(LoggingService::class),
            'requestLifecycle' => null,
            'tokenBudget' => new TokenBudgetService(new TokenCounter(new class
            {
                public function encode(string $text): array
                {
                    return array_fill(0, mb_strlen($text), 1);
                }
            })),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(AIAssistantService::class, $name))->setValue($service, $value);
        }

        return $service;
    }
}
