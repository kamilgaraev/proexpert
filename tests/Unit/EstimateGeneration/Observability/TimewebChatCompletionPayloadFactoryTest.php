<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TimewebChatCompletionPayloadFactoryTest extends TestCase
{
    public function test_luna_uses_bounded_completion_and_no_reasoning(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory())->make('openai/gpt-6-luna', [
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
        (new TimewebChatCompletionPayloadFactory())->make('openai/gpt-5-mini', [], ['max_tokens' => 512]);
    }

    public function test_unsupported_provider_is_rejected_before_wire_call(): void
    {
        $this->expectException(DomainException::class);
        (new TimewebChatCompletionPayloadFactory())->make('another-provider/gpt-6-luna', [], []);
    }
}