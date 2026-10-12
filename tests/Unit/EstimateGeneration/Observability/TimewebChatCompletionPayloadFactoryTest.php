<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TimewebChatCompletionPayloadFactoryTest extends TestCase
{
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
