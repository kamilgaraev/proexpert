<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\LLM\DeepSeekProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\Models\User;
use App\Services\Logging\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantNativeResponsesTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public static function providers(): array
    {
        return [['timeweb', 'openai/gpt-6-luna'], ['openai', 'gpt-6-luna']];
    }

    #[DataProvider('providers')]
    public function test_native_roundtrip_keeps_wire_items_call_id_and_actual_evidence(string $name, string $model): void
    {
        $call = self::call();
        $text = self::text('Две задачи.');
        $history = [];
        $provider = $this->provider($name, $model, [$this->wire($model, [$text, $call]), $this->wire($model, [self::text('Готово.')], ['id' => 'resp_final'])], $history);
        $input = [['role' => 'system', 'content' => 'Соблюдай права.'], ['role' => 'user', 'content' => 'Проверь проект.']];
        $first = $provider->responses($input, ['tools' => self::tools(), 'store' => true, 'previous_response_id' => 'ignored']);
        self::assertEquals([$text, $call], $first['output']);
        self::assertEquals([$call], $first['function_calls']);
        self::assertArrayNotHasKey('tool_calls', $first);
        $next = [...$input, ...$first['output'], ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => '{"tasks":2}']];
        $final = $provider->responses($next, ['tools' => self::tools()]);
        self::assertSame($model, $final['actual_model']);
        self::assertSame('resp_final', $final['provider_response_ref']);
        self::assertTrue($final['model_invoked']);
        self::assertSame('responses', $final['api_method']);
        self::assertSame('Готово.', $final['content']);
        self::assertCount(2, $history);
        foreach ($history as $index => $request) {
            $payload = json_decode((string) $request['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('/v1/responses', $request['request']->getUri()->getPath());
            self::assertSame($model, $payload['model']);
            self::assertFalse($payload['store']);
            self::assertFalse($payload['stream']);
            self::assertFalse($payload['parallel_tool_calls']);
            self::assertSame(['effort' => 'none'], $payload['reasoning']);
            self::assertSame(self::tools(), $payload['tools']);
            self::assertArrayNotHasKey('previous_response_id', $payload);
            self::assertArrayNotHasKey('messages', $payload);
            self::assertSame($index === 0 ? $input : $next, $payload['input']);
        }
    }

    public static function invalidResponses(): array
    {
        $call = self::call();
        return [
            'duplicate-call' => [[$call, array_replace($call, ['id' => 'fc_second'])], [], \DomainException::class],
            'parallel' => [[$call, array_replace($call, ['id' => 'fc_second', 'call_id' => 'call_other'])], [], \DomainException::class],
            'unknown' => [[array_replace($call, ['name' => 'not_allowed'])], [], \DomainException::class],
            'bad-json' => [[array_replace($call, ['arguments' => '{'])], [], \DomainException::class],
            'array-args' => [[array_replace($call, ['arguments' => '[]'])], [], \DomainException::class],
            'missing-id' => [[array_replace($call, ['call_id' => ''])], [], \DomainException::class],
            'pending-call' => [[array_replace($call, ['status' => 'in_progress'])], [], \DomainException::class],
            'legacy' => [[['id' => 'legacy', 'type' => 'tool_calls']], [], \Throwable::class],
            'reordered' => [[$call, self::text('После вызова')], [], \DomainException::class],
            'unsafe-reasoning' => [[['type' => 'reasoning', 'id' => 'rs_test', 'summary' => ['raw']]], [], \Throwable::class],
            'empty' => [[], [], \DomainException::class],
            'mismatch' => [[self::text('ok')], ['model' => 'another-model'], \DomainException::class],
            'incomplete' => [[self::text('partial')], ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], AssistantResponseIncomplete::class],
            'failed' => [[self::text('partial')], ['status' => 'failed'], \DomainException::class],
            'error' => [[self::text('partial')], ['error' => ['code' => 'server_error', 'message' => 'failure']], \DomainException::class],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_invalid_output_without_retry_or_chat_fallback(array $output, array $changes, string $exception): void
    {
        $history = [];
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', [$this->wire('openai/gpt-6-luna', $output, $changes)], $history);
        try {
            $provider->responses([['role' => 'user', 'content' => 'Состояние']], ['tools' => self::tools()]);
            self::fail('Expected invalid output rejection');
        } catch (\Throwable $failure) {
            self::assertInstanceOf($exception, $failure);
            self::assertCount(1, $history);
        }
    }

    public function test_unsupported_provider_and_legacy_input_have_zero_outbound(): void
    {
        $deepSeek = new DeepSeekProvider($this->createMock(LoggingService::class));
        try {
            $deepSeek->responses([]);
            self::fail('Unsupported Responses');
        } catch (\DomainException $exception) {
            self::assertSame('ai_luna_provider_required', $exception->getMessage());
        }
        $history = [];
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', [], $history);
        foreach ([['role' => 'tool', 'content' => 'orphan'], ['role' => 'assistant', 'tool_calls' => [self::call()]]] as $item) {
            try {
                $provider->responses([$item], ['tools' => self::tools()]);
                self::fail('Legacy input');
            } catch (\DomainException) {
                self::assertSame([], $history);
            }
        }
    }

    public function test_bound_deadline_fence_stops_native_outbound(): void
    {
        $history = [];
        $provider = $this->provider('timeweb', 'openai/gpt-6-luna', [], $history);
        $lifecycle = (new \ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
        $context = new AssistantRequestExecutionContext($lifecycle, new AssistantRequest, new User, 1);
        (new \ReflectionProperty($context, 'cleanupDeadlineNanoseconds'))->setValue($context, 0);
        app()->instance(AssistantRequestExecutionContext::class, $context);
        try {
            $provider->responses([['role' => 'user', 'content' => 'Проверь']]);
            self::fail('Expected stopped request');
        } catch (AssistantRequestDeadlineExceeded) {
            self::assertSame([], $history);
        } finally {
            app()->forgetInstance(AssistantRequestExecutionContext::class);
        }
    }

    private function provider(string $name, string $model, array $responses, array &$history): TimewebProvider|OpenAIProvider
    {
        config()->set("ai-assistant.llm.{$name}.api_key", 'test-key');
        config()->set("ai-assistant.llm.{$name}.base_uri", 'https://provider.test/v1');
        config()->set("ai-assistant.llm.{$name}.model", $model);
        config()->set('ai-assistant.llm.timeweb.profiles.assistant.models', [$model]);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        return $name === 'timeweb' ? new TimewebProvider($this->createMock(LoggingService::class), $client)
            : new OpenAIProvider($this->createMock(LoggingService::class), $client);
    }

    private static function call(): array
    {
        return ['type' => 'function_call', 'id' => 'fc_test', 'status' => 'completed', 'call_id' => 'call_project', 'name' => 'lookup', 'arguments' => '{"project_id":42}'];
    }

    private static function text(string $text): array
    {
        return ['type' => 'message', 'id' => 'msg_test', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }

    private static function tools(): array
    {
        return [['type' => 'function', 'name' => 'lookup', 'description' => 'Lookup', 'parameters' => ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer']]], 'strict' => false]];
    }

    private function wire(string $model, array $output, array $changes = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_replace([
            'id' => 'resp_test', 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed', 'model' => $model,
            'output' => $output, 'max_output_tokens' => 1200, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 20, 'total_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], $changes), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
