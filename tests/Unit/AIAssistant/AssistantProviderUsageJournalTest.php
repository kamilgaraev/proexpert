<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class AssistantProviderUsageJournalTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('providerAttempts')]
    public function test_monitor_uses_credit_attempt_key_and_keeps_success_cost_availability_separate(array $response, bool $successful, bool $available): void
    {
        $service = (new ReflectionClass(ProviderUsageBoundaryService::class))->newInstanceWithoutConstructor();
        $tracker = $this->createMock(UsageTracker::class);
        $tracker->expects($this->once())->method('recordUsage')->with(
            15, 7, 'timeweb', 'openai/gpt-6-luna', 'assistant_chat',
            $response['input_tokens'] ?? 0, $response['output_tokens'] ?? 0,
            ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0),
            $this->callback(static fn (array $metadata): bool => $metadata['credit_usage_key'] === 'request-fixture:call:2'
                && $metadata['request_id'] === 'request-fixture' && $metadata['attempt'] === 2
                && $metadata['is_successful'] === $successful && $metadata['provider_usage_available'] === $available
                && $metadata['cost_available'] === $available)
        );
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        foreach (['usageTracker' => $tracker, 'logging' => $this->createMock(LoggingService::class), 'llmProvider' => $provider,
            'activeRequest' => new AssistantRequest(['request_id' => 'request-fixture'])] as $property => $value) {
            (new ReflectionProperty(AIAssistantService::class, $property))->setValue($service, $value);
        }
        $actor = new User;
        $actor->id = 7;
        $service->record($response + ['provider' => 'timeweb'], $actor, $successful);
    }

    public static function providerAttempts(): array
    {
        return [
            'successful known usage' => [['input_tokens' => 100, 'output_tokens' => 40], true, true],
            'incomplete retains known usage' => [['input_tokens' => 100, 'output_tokens' => 40], false, true],
            'HTTP error unknown usage' => [[], false, false],
            'observed zero usage' => [['input_tokens' => 0, 'output_tokens' => 0], false, true],
        ];
    }

    public function test_HTTP_error_actual_usage_is_whitelisted_and_response_stream_position_is_preserved(): void
    {
        $response = new \GuzzleHttp\Psr7\Response(429, [], json_encode(['model' => 'openai/gpt-6-luna', 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7], 'error' => ['message' => 'Private provider error']], JSON_THROW_ON_ERROR));
        $response->getBody()->seek(5);
        $exception = new class($response) extends \RuntimeException {
            public function __construct(public \Psr\Http\Message\ResponseInterface $response) {}
        };
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'providerUsageFromFailure');

        $actual = $method->invoke($service, $exception, null);

        $this->assertSame(['input_tokens' => 11, 'output_tokens' => 7, 'tokens_used' => 18, 'model' => 'openai/gpt-6-luna'], $actual);
        $this->assertSame(5, $response->getBody()->tell());
        $this->assertSame([], $method->invoke($service, new \RuntimeException('HTTP error without usage'), null));
        $known = ['input_tokens' => 4, 'output_tokens' => 2];
        $this->assertSame($known, $method->invoke($service, new \RuntimeException('Local publication failure'), $known));
    }
}

final class ProviderUsageBoundaryService extends AIAssistantService
{
    public function record(array $response, User $actor, bool $successful): void
    {
        $this->recordAssistantProviderUsage($response, 15, $actor, [], !$successful, $successful, 2);
    }
}
