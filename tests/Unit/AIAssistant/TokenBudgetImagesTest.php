<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use App\Support\AI\LunaModelPolicy;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

final class TokenBudgetImagesTest extends TestCase
{
    public function test_image_payload_size_does_not_add_base64_to_token_count(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $service = new TokenBudgetService($counter, new Repository(new ArrayStore));

        $small = $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Посмотри изображение'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AA==']],
        ]]], [], 'short');
        $large = $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Посмотри изображение'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.str_repeat('A', 200_000)]],
        ]]], [], 'short');

        self::assertSame($small['raw_input_tokens'], $large['raw_input_tokens']);
    }

    public function test_each_image_reserves_4096_tokens_in_addition_to_text(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $service = new TokenBudgetService($counter, new Repository(new ArrayStore));
        $textOnly = $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Проверь'],
        ]]], [], 'short');
        $withImage = $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Проверь'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/image.png']],
        ]]], [], 'short');

        self::assertSame(4096, $withImage['raw_input_tokens'] - $textOnly['raw_input_tokens']);
    }

    public function test_two_images_and_current_prompt_cannot_be_trimmed_out_of_short_budget(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $service = new TokenBudgetService($counter, new Repository(new ArrayStore));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ai_token_budget_exhausted');
        $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Сравни изображения'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/one.png']],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/two.png']],
        ]]], [], 'short');
    }

    public function test_normal_budget_keeps_system_rules_and_full_current_multimodal_query(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $current = [
            ['type' => 'text', 'text' => 'Сравни оба снимка'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,one']],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,two']],
        ];
        $prepared = (new TokenBudgetService($counter, new Repository(new ArrayStore)))->prepare([
            ['role' => 'system', 'content' => str_repeat('S', 4000)],
            ['role' => 'user', 'content' => str_repeat('H', 6000)],
            ['role' => 'user', 'content' => $current],
        ], [], 'normal');

        self::assertSame(str_repeat('S', 4000), $prepared['messages'][0]['content']);
        self::assertSame($current, $prepared['messages'][array_key_last($prepared['messages'])]['content']);
        self::assertSame(['system', 'user'], array_column($prepared['messages'], 'role'));
    }

    public function test_multimodal_calibration_does_not_change_text_safety_factor(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $cache = new Repository(new ArrayStore);
        $service = new TokenBudgetService($counter, $cache);
        $multimodal = $service->prepare([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Сравни'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,ignored']],
        ]]], [], 'short');

        $calibration = $service->calibration($multimodal, $multimodal['raw_input_tokens'] * 4);
        $textOnly = $service->prepare([['role' => 'user', 'content' => 'Обычный текст']], [], 'short');

        self::assertTrue($calibration['text_calibration_skipped']);
        self::assertSame(1.1, $calibration['safety_factor']);
        self::assertSame(1.1, $textOnly['safety_factor']);
    }

    public function test_high_text_calibration_does_not_multiply_fixed_two_image_cost_or_trim_current_text(): void
    {
        $counter = new TokenCounter(new class
        {
            public function encode(string $text): array
            {
                return array_fill(0, mb_strlen($text), 1);
            }
        });
        $cache = new Repository(new ArrayStore);
        $cache->forever('ai:token-calibration:'.hash('sha256', LunaModelPolicy::TIMEWEB.':o200k-compatible:v1'), 4.0);
        $service = new TokenBudgetService($counter, $cache);
        $currentText = str_repeat('Текстовое описание. ', 75);
        $current = [
            ['type' => 'text', 'text' => $currentText],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,first']],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,second']],
        ];
        $prepared = $service->prepare([['role' => 'user', 'content' => $current]], [], 'normal');
        $raw = $counter->messages($prepared['messages']);
        $imageTokens = $counter->imageTokens($prepared['messages']);

        self::assertSame($current, $prepared['messages'][0]['content']);
        self::assertSame(8192, $imageTokens);
        self::assertSame((int) ceil(($raw - $imageTokens) * 4.0) + $imageTokens, $prepared['input_tokens']);
        self::assertLessThanOrEqual($prepared['input_limit'], $prepared['input_tokens']);
    }
}
