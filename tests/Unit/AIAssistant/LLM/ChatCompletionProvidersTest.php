<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\Services\Logging\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class ChatCompletionProvidersTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('lunaProviders')]
    public function test_luna_tool_round_trip_preserves_messages_and_api_contract(string $providerName, string $model): void
    {
        $toolCall = [
            'id' => 'call_project',
            'type' => 'function',
            'function' => ['name' => 'get_project_snapshot', 'arguments' => '{"project_id":42}'],
        ];
        $history = [];
        $httpClient = $this->httpClient([
            $this->completion($model, null, [$toolCall]),
            $this->completion($model, 'По проекту осталось две открытые задачи.'),
        ], $history);
        $provider = $this->provider($providerName, $model, $httpClient);
        $messages = [
            ['role' => 'system', 'content' => 'Работай только с доступными проектами.'],
            ['role' => 'user', 'content' => 'Покажи состояние проекта 42.'],
        ];
        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'get_project_snapshot',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer']],
                    'required' => ['project_id'],
                ],
            ],
        ]];
        $options = ['tools' => $tools, 'tool_choice' => 'auto', 'max_tokens' => 1200];

        $toolResponse = $provider->chat($messages, $options);
        self::assertSame([$toolCall], $toolResponse['tool_calls']);
        self::assertSame('tool_calls', $toolResponse['finish_reason']);
        $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolResponse['tool_calls']];
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'call_project', 'content' => '{"open_tasks":2}'];

        $answer = $provider->chat($messages, $options);

        self::assertSame('По проекту осталось две открытые задачи.', $answer['content']);
        self::assertSame($model, $answer['model']);
        self::assertSame($providerName, $answer['provider']);
        self::assertSame(100, $answer['tokens_used']);
        self::assertCount(2, $history);
        foreach ($history as $transaction) {
            $payload = json_decode((string) $transaction['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($model, $payload['model']);
            self::assertSame(1200, $payload[$providerName === 'timeweb' ? 'max_output_tokens' : 'max_completion_tokens']);
            self::assertSame('none', $providerName === 'timeweb' ? $payload['reasoning']['effort'] : $payload['reasoning_effort']);
            self::assertSame($providerName === 'timeweb' ? [['type' => 'function', ...$tools[0]['function'], 'strict' => false]] : $tools, $payload['tools']);
            self::assertSame('auto', $payload['tool_choice']);
            self::assertArrayNotHasKey('max_tokens', $payload);
        }
        $lastPayload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($providerName === 'timeweb') {
            self::assertSame([
                ...array_slice($messages, 0, 2),
                ['type' => 'function_call', 'call_id' => 'call_project', 'name' => 'get_project_snapshot', 'arguments' => '{"project_id":42}'],
                ['type' => 'function_call_output', 'call_id' => 'call_project', 'output' => '{"open_tasks":2}'],
            ], $lastPayload['input']);
            self::assertFalse($lastPayload['store']);
            self::assertArrayNotHasKey('previous_response_id', $lastPayload);
            self::assertSame('/v1/responses', $history[1]['request']->getUri()->getPath());
        } else {
            self::assertSame($messages, $lastPayload['messages']);
        }
    }

    public static function lunaProviders(): array
    {
        return [
            'OpenAI alias' => ['openai', 'gpt-6-luna'],
            'Timeweb alias' => ['timeweb', 'openai/gpt-6-luna'],
        ];
    }

    public function test_timeweb_failure_does_not_fall_back_to_gemini(): void
    {
        $history = [];
        $httpClient = $this->httpClient([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['message' => 'Model temporarily unavailable', 'type' => 'invalid_request_error'],
            ], JSON_THROW_ON_ERROR)),
            $this->completion('gemini/gemini-2.5-flash', 'Ответ резервной модели.'),
        ], $history);
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', $httpClient);
        config()->set('ai-assistant.llm.timeweb.profiles.assistant.models', [
            'openai/gpt-6-luna', 'gemini/gemini-2.5-flash',
        ]);

        $this->expectException(\OpenAI\Exceptions\ErrorException::class);
        $this->expectExceptionMessage('Model temporarily unavailable');

        try {
            $provider->chat([['role' => 'user', 'content' => 'Состояние проекта']], [
                'max_tokens' => 900,
                'enable_thinking' => false,
            ]);
        } finally {
            self::assertCount(1, $history);
            $primary = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('openai/gpt-6-luna', $primary['model']);
            self::assertArrayNotHasKey('enable_thinking', $primary);
        }
    }

    public function test_timeweb_incomplete_throws_safe_usage_exception_without_retry(): void
    {
        $history = [];
        $attributes = json_decode((string) $this->completion('openai/gpt-6-luna', 'Секретный обрезанный текст')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $attributes['status'] = 'incomplete';
        $attributes['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $httpClient = $this->httpClient([new Response(200, ['Content-Type' => 'application/json'], json_encode($attributes, JSON_THROW_ON_ERROR))], $history);
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', $httpClient);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Запрос']]);
            self::fail('Incomplete response must throw');
        } catch (AssistantResponseIncomplete $exception) {
            self::assertSame('max_output_tokens', $exception->reason);
            self::assertSame(80, $exception->providerUsage['input_tokens']);
            self::assertSame(20, $exception->providerUsage['output_tokens']);
            self::assertArrayNotHasKey('content', $exception->providerUsage);
            self::assertArrayNotHasKey('tool_calls', $exception->providerUsage);
            self::assertStringNotContainsString('Секретный', $exception->getMessage());
        }
        self::assertCount(1, $history);
    }

    #[DataProvider('timewebProfiles')]
    public function test_timeweb_profiles_ignore_obsolete_gemini_models(string $profile): void
    {
        $history = [];
        $httpClient = $this->httpClient([$this->completion('openai/gpt-6-luna', 'Ответ.')], $history);
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', $httpClient);
        config()->set("ai-assistant.llm.timeweb.profiles.{$profile}.models", ['gemini/gemini-3.1-flash-lite']);

        $answer = $provider->chat([['role' => 'user', 'content' => 'Состояние проекта']], ['profile' => $profile]);

        self::assertSame('openai/gpt-6-luna', $answer['model']);
        self::assertFalse($answer['route_fallback']);
        self::assertCount(1, $history);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('openai/gpt-6-luna', $payload['model']);
    }

    public static function timewebProfiles(): array
    {
        return array_map(static fn (string $profile): array => [$profile], ['assistant', 'json', 'fast', 'premium']);
    }

    public function test_config_replaces_obsolete_model_environment_with_luna(): void
    {
        $keys = ['OPENAI_MODEL', 'TIMEWEB_AI_MODEL', 'TIMEWEB_AI_ASSISTANT_MODELS', 'TIMEWEB_AI_JSON_MODELS', 'TIMEWEB_AI_FAST_MODELS', 'TIMEWEB_AI_PREMIUM_MODELS'];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = ['exists' => array_key_exists($key, $_ENV), 'value' => $_ENV[$key] ?? null];
            $_ENV[$key] = 'gemini/gemini-3.1-flash-lite,anthropic/claude-4.6-sonnet';
        }

        try {
            $config = require base_path('app/BusinessModules/Features/AIAssistant/config/ai-assistant.php');
            self::assertSame('gpt-6-luna', $config['llm']['openai']['model']);
            self::assertSame('gpt-6-luna', $config['openai_model']);
            self::assertSame('openai/gpt-6-luna', $config['llm']['timeweb']['model']);
            foreach (['assistant', 'json', 'fast', 'premium'] as $profile) {
                self::assertSame(['openai/gpt-6-luna'], $config['llm']['timeweb']['profiles'][$profile]['models']);
            }
        } finally {
            foreach ($original as $key => $previous) {
                if ($previous['exists']) {
                    $_ENV[$key] = $previous['value'];
                } else {
                    unset($_ENV[$key]);
                }
            }
        }
    }

    public function test_openai_legacy_model_override_is_rejected(): void
    {
        $history = [];
        $httpClient = $this->httpClient([$this->completion('gpt-4o-mini', 'Ответ.')], $history);
        $provider = $this->provider('openai', 'gpt-6-luna', $httpClient);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ai_luna_model_required');

        try {
            $provider->chat([['role' => 'user', 'content' => 'Состояние проекта']], [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 900,
            ]);
        } finally {
            self::assertCount(0, $history);
        }
    }

    #[DataProvider('lunaProviders')]
    public function test_budget_profile_limits_output_and_rejects_another_returned_model(string $providerName, string $model): void
    {
        $history = [];
        $httpClient = $this->httpClient([
            $this->completion($model, 'Ответ.'),
            $providerName === 'timeweb'
                ? new Response(200, ['Content-Type' => 'application/json'], str_replace('openai/gpt-6-luna', 'gemini/gemini-3.1-flash-lite', (string) $this->completion('openai/gpt-6-luna', 'Неверная модель.')->getBody()))
                : $this->completion('gemini/gemini-3.1-flash-lite', 'Неверная модель.'),
        ], $history);
        $provider = $this->provider($providerName, $model, $httpClient);
        $options = ['profile' => 'assistant', 'budget_profile' => 'short', 'max_completion_tokens' => 100000];
        $provider->chat([['role' => 'user', 'content' => 'запрос']], $options);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1024, $payload[$providerName === 'timeweb' ? 'max_output_tokens' : 'max_completion_tokens']);
        $this->expectException(\DomainException::class);
        $provider->chat([['role' => 'user', 'content' => 'запрос']], $options);
    }

    #[DataProvider('lunaProviders')]
    public function test_server_snapshot_controls_wire_output_after_config_change(string $providerName, string $model): void
    {
        config()->set('ai-assistant-credits.profiles.short', ['input_tokens' => 512, 'output_tokens' => 111, 'max_calls' => 1]);
        $history = [];
        $provider = $this->provider($providerName, $model, $this->httpClient([$this->completion($model, 'Ответ.')], $history));
        $provider->chat([['role' => 'user', 'content' => 'запрос']], [
            'budget_profile' => 'short',
            'budget_limits' => ['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2],
            'max_completion_tokens' => 2000,
        ]);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1024, $payload[$providerName === 'timeweb' ? 'max_output_tokens' : 'max_completion_tokens']);
    }

    private function provider(string $providerName, string $model, Client $httpClient): LLMProviderInterface
    {
        config()->set("ai-assistant.llm.{$providerName}.api_key", 'test-key');
        config()->set("ai-assistant.llm.{$providerName}.base_uri", 'https://provider.test/v1');
        config()->set("ai-assistant.llm.{$providerName}.model", $model);
        config()->set('ai-assistant.llm.timeweb.profiles.assistant.models', [$model]);
        $logging = $this->createMock(LoggingService::class);

        return $providerName === 'timeweb'
            ? new TimewebProvider($logging, $httpClient)
            : new OpenAIProvider($logging, $httpClient);
    }

    private function httpClient(array $responses, array &$history): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        return new Client(['handler' => $handler]);
    }

    private function completion(string $model, ?string $content, array $toolCalls = []): Response
    {
        if (str_starts_with($model, 'openai/')) {
            $output = [];
            if ($content !== null) {
                $output[] = ['type' => 'message', 'id' => 'msg_test', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $content, 'annotations' => []]]];
            }
            foreach ($toolCalls as $call) {
                $output[] = ['type' => 'function_call', 'id' => 'fc_test', 'call_id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => $call['function']['arguments'], 'status' => 'completed'];
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'resp_test', 'object' => 'response', 'created_at' => 1790596800, 'status' => 'completed', 'model' => $model,
                'output' => $output, 'max_output_tokens' => 1200, 'parallel_tool_calls' => true, 'previous_response_id' => null,
                'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
                'usage' => ['input_tokens' => 80, 'output_tokens' => 20, 'total_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 1790596800,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => $toolCalls === [] ? 'stop' : 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
