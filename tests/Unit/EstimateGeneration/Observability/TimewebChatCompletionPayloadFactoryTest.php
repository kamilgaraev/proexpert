<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use DomainException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimewebChatCompletionPayloadFactoryTest extends TestCase
{
    public function test_json_object_response_format_is_an_explicit_opt_in(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'Return JSON'],
        ], ['profile' => 'json', 'response_format' => ['type' => 'json_object']]);
        self::assertSame(['type' => 'json_object'], $payload['response_format'] ?? null);
    }

    public function test_json_budget_profile_does_not_change_the_default_response_format(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'Return JSON'],
        ], ['profile' => 'json']);
        self::assertSame(['model', 'messages', 'max_completion_tokens', 'reasoning_effort'], array_keys($payload));
    }

    public function test_sdk_serializes_the_explicit_json_object_option_into_the_http_request(): void
    {
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-fixture', 'object' => 'chat.completion', 'created' => 1,
                'model' => 'openai/gpt-6-luna', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
                    'content' => '{"work_intents":[]}'], 'finish_reason' => 'stop', 'logprobs' => null]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $sdk = \OpenAI::factory()->withApiKey('fixture-not-a-real-key')->withBaseUri('https://timeweb.example.test/v1')
            ->withHttpClient(new \GuzzleHttp\Client(['handler' => $handler]))->make();
        $response = $sdk->chat()->create((new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'Return JSON'],
        ], ['profile' => 'json', 'response_format' => ['type' => 'json_object']]));
        self::assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'json_object'], $body['response_format'] ?? null);
        self::assertSame('{"work_intents":[]}', $response->choices[0]->message->content);
    }

    #[DataProvider('invalidResponseFormats')]
    public function test_explicit_invalid_response_format_is_rejected(mixed $format): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimate_text_response_format_invalid');
        (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'Return JSON'],
        ], ['response_format' => $format]);
    }

    public static function invalidResponseFormats(): iterable
    {
        foreach ([null, true, 'json_object', [], ['type' => 'text'], ['type' => 'json_schema'],
            ['type' => 'json_object', 'strict' => true]] as $value) {
            yield [$value];
        }
    }

    public function test_a_pathological_text_segment_is_rejected_before_the_tokenizer(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimate_text_segment_requires_source_split');
        (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => json_encode(['value' => str_repeat('x', 200000)], JSON_THROW_ON_ERROR)],
        ], ['profile' => 'json']);
    }

    public function test_luna_uses_bounded_completion_and_no_reasoning(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'Return JSON'],
        ], ['max_tokens' => 20000, 'temperature' => 0.7]);
        self::assertSame(4096, $payload['max_completion_tokens']);
        self::assertSame('none', $payload['reasoning_effort']);
        self::assertArrayNotHasKey('max_tokens', $payload);
        self::assertArrayNotHasKey('temperature', $payload);
    }

    public function test_old_queued_model_is_rejected_before_wire_call(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ai_luna_model_required');
        (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-5-mini', [], ['max_tokens' => 512]);
    }

    public function test_unsupported_provider_is_rejected_before_wire_call(): void
    {
        $this->expectException(DomainException::class);
        (new TimewebChatCompletionPayloadFactory)->make('another-provider/gpt-6-luna', [], []);
    }
}
