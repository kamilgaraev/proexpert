<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimewebChatCompletionPayloadFactoryTest extends TestCase
{
    #[Test]
    public function gpt_five_uses_completion_budget_without_temperature(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make(
            'openai/gpt-5-mini',
            [['role' => 'user', 'content' => 'Return JSON']],
            ['max_tokens' => 4096, 'temperature' => 0.7],
        );

        self::assertSame(4096, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $payload);
        self::assertArrayNotHasKey('temperature', $payload);
        self::assertArrayNotHasKey('reasoning_effort', $payload);
    }

    #[Test]
    public function gpt_five_detection_supports_provider_prefix_and_versioned_models(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make(
            'openai/gpt-5.1-nano',
            [],
            ['max_tokens' => 512],
        );

        self::assertSame(512, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $payload);
        self::assertArrayNotHasKey('temperature', $payload);
    }

    #[Test]
    public function other_models_keep_standard_chat_completion_parameters(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make(
            'anthropic/claude-sonnet',
            [],
            ['max_tokens' => 800, 'temperature' => 0.25],
        );

        self::assertSame(800, $payload['max_tokens']);
        self::assertSame(0.25, $payload['temperature']);
        self::assertArrayNotHasKey('max_completion_tokens', $payload);
        self::assertArrayNotHasKey('reasoning_effort', $payload);
    }

    #[Test]
    public function similarly_named_model_from_another_provider_is_not_treated_as_openai_gpt_five(): void
    {
        $payload = (new TimewebChatCompletionPayloadFactory)->make(
            'another-provider/gpt-5-mini',
            [],
            ['max_tokens' => 300, 'temperature' => 0],
        );

        self::assertSame(300, $payload['max_tokens']);
        self::assertSame(0.0, $payload['temperature']);
        self::assertArrayNotHasKey('max_completion_tokens', $payload);
    }
}
