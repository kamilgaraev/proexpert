<?php

declare(strict_types=1);

namespace App\Support\AI;

use DomainException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

final class TokenBudgetService
{
    private const PROFILES = [
        'short' => ['input' => 8192, 'output' => 1024, 'calls' => 2],
        'normal' => ['input' => 16384, 'output' => 2048, 'calls' => 4],
        'detailed' => ['input' => 32768, 'output' => 4096, 'calls' => 6],
    ];

    private const COUNTING_VERSION = 'o200k-compatible:v1';

    public function __construct(
        private readonly TokenCounter $counter = new TokenCounter(),
        private readonly ?Repository $calibrationCache = null,
        private readonly string $calibrationModel = LunaModelPolicy::TIMEWEB,
    ) {
    }

    public function prepare(array $messages, array $tools = [], string $profile = 'normal', ?array $snapshotLimits = null): array
    {
        $profile = self::normalizeProfile($profile);
        $configured = app()->bound('config') ? config("ai-assistant-credits.profiles.{$profile}") : null;
        $limits = $this->normalizeLimits($snapshotLimits ?? $configured ?? self::limits($profile));
        $factor = $this->safetyFactor();
        $messages = $this->trimMessages($messages, $tools, (int) floor($limits['input'] * 0.99), $factor);
        $inputTokens = $this->counter->messages($messages);
        $toolsTokens = $this->counter->tools($tools);
        $imageTokens = $this->counter->imageTokens($messages);
        $weightedInput = (int) ceil(($inputTokens + $toolsTokens - $imageTokens) * $factor) + $imageTokens;

        $defaultPricing = ['input_micro_rub_per_million' => 13_500_000, 'output_micro_rub_per_million' => 67_500_000];
        $pricing = app()->bound('config') ? config('ai-assistant-credits.pricing', $defaultPricing) : $defaultPricing;
        if (!is_array($pricing)) { throw new DomainException('ai_token_pricing_invalid'); }
        $inputPrice = $pricing['input_micro_rub_per_million'] ?? null;
        $outputPrice = $pricing['output_micro_rub_per_million'] ?? null;
        $maximumPrice = intdiv(PHP_INT_MAX, ($limits['input'] + $limits['output']) * $limits['calls']);
        if (!is_int($inputPrice) || !is_int($outputPrice) || $inputPrice < 0 || $outputPrice < 0 || $inputPrice > $maximumPrice || $outputPrice > $maximumPrice) {
            throw new DomainException('ai_token_pricing_invalid');
        }

        return [
            'messages' => $messages,
            'tools' => $tools,
            'profile' => self::normalizeProfile($profile),
            'input_limit' => $limits['input'],
            'budget_limits' => ['input_tokens' => $limits['input'], 'output_tokens' => $limits['output'], 'max_calls' => $limits['calls']],
            'input_tokens' => $weightedInput,
            'raw_input_tokens' => $inputTokens + $toolsTokens,
            'safety_factor' => $factor,
            'message_tokens' => $inputTokens,
            'tools_tokens' => $toolsTokens,
            'max_completion_tokens' => $limits['output'],
            'max_calls' => $limits['calls'],
            'estimated_cost_rub' => round(($weightedInput * $inputPrice + $limits['output'] * $outputPrice) / 1_000_000_000_000, 6),
            'max_cost_rub' => ($limits['input'] * $inputPrice + $limits['output'] * $outputPrice) * $limits['calls'] / 1_000_000_000_000,
            'token_count_encoding' => 'o200k_base',
            'token_count_exact' => false,
            'contains_images' => $this->containsImages($messages),
        ];
    }

    public function build(array $messages, array $tools = [], string $profile = 'normal', ?array $snapshotLimits = null): array
    {
        return $this->prepare($messages, $tools, $profile, $snapshotLimits);
    }

    public function count(string $text): int
    {
        return $this->counter->text($text);
    }

    public function calibration(array $prepared, int $actualInputTokens): array
    {
        $estimated = max(1, (int) ($prepared['raw_input_tokens'] ?? $prepared['input_tokens'] ?? 0));
        $ratio = $actualInputTokens > 0 ? $actualInputTokens / $estimated : null;
        $cache = $this->cache();
        $persisted = true;
        $factor = (float) ($prepared['safety_factor'] ?? 1.1);
        if ($ratio !== null && $ratio > 1.0 && $cache !== null && !($prepared['contains_images'] ?? false)) {
            try {
                $lock = app()->bound('cache') ? app('cache')->lock($this->calibrationKey().':lock', 5) : null;
                $update = function () use ($cache, $ratio, &$factor): void {
                    $factor = max($this->safetyFactor(), $ratio * 1.1);
                    $cache->forever($this->calibrationKey(), $factor);
                };
                if ($lock !== null) {
                    $lock->block(2, $update);
                } else {
                    $update();
                }
            } catch (Throwable) {
                $persisted = false;
            }
        }

        return [
            'encoding' => 'o200k_base',
            'estimated_input_tokens' => $estimated,
            'actual_input_tokens' => max(0, $actualInputTokens),
            'actual_to_estimate_ratio' => $ratio,
            'safety_factor' => $factor,
            'persisted' => $persisted,
            'text_calibration_skipped' => (bool) ($prepared['contains_images'] ?? false),
            'profile_input_exceeded' => $actualInputTokens > (int) ($prepared['input_limit'] ?? self::limits((string) ($prepared['profile'] ?? 'normal'))['input']),
            'exact' => false,
        ];
    }

    private function safetyFactor(): float
    {
        $value = $this->cache()?->get($this->calibrationKey(), 1.1);

        return is_numeric($value) ? max(1.1, (float) $value) : 1.1;
    }

    private function containsImages(array $messages): bool
    {
        foreach ($messages as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
                if (($part['type'] ?? null) === 'image_url') { return true; }
            }
        }
        return false;
    }

    private function calibrationKey(): string
    {
        return 'ai:token-calibration:'.hash('sha256', $this->calibrationModel.':'.self::COUNTING_VERSION);
    }

    private function cache(): ?Repository
    {
        if ($this->calibrationCache !== null) {
            return $this->calibrationCache;
        }

        return app()->bound('cache') ? app('cache')->store() : null;
    }

    public static function limits(string $profile): array
    {
        return self::PROFILES[self::normalizeProfile($profile)];
    }

    private function normalizeLimits(mixed $limits): array
    {
        if (!is_array($limits)) {
            throw new DomainException('ai_token_limits_invalid');
        }
        $normalized = [];
        foreach (['input' => ['input_tokens', 32768], 'output' => ['output_tokens', 4096], 'calls' => ['max_calls', 6]] as $key => [$alias, $maximum]) {
            $value = $limits[$alias] ?? $limits[$key] ?? null;
            if (!is_int($value) || $value < 1 || $value > $maximum
                || (isset($limits[$alias], $limits[$key]) && $limits[$alias] !== $limits[$key])) {
                throw new DomainException('ai_token_limits_invalid');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function trimMessages(array $messages, array $tools, int $limit, float $factor): array
    {
        $available = $limit - (int) ceil($this->counter->tools($tools) * $factor);
        if ($available < 1) {
            throw new DomainException('ai_token_budget_exhausted');
        }
        $currentQuery = $this->currentQueryIndex($messages);
        while (ceil(($this->counter->messages($messages) - $this->counter->imageTokens($messages)) * $factor) + $this->counter->imageTokens($messages) > $available) {
            $drop = $this->lowestPriorityIndex($messages, $currentQuery);
            if ($drop === null) {
                throw new DomainException('ai_token_budget_exhausted');
            }
            $end = $drop + 1;
            while ($end < count($messages) && ($messages[$end]['role'] ?? null) === 'tool') {
                $end++;
            }
            array_splice($messages, $drop, $end - $drop);
            $currentQuery = $this->currentQueryIndex($messages);
        }

        return array_values($messages);
    }

    private function currentQueryIndex(array $messages): ?int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                return $index;
            }
        }

        return null;
    }

    private function lowestPriorityIndex(array $messages, ?int $currentQuery): ?int
    {
        foreach ($messages as $index => $message) {
            if (is_array($message['content'] ?? null) && array_filter($message['content'], static fn (array $part): bool => ($part['type'] ?? null) === 'image_url') !== []) {
                continue;
            }
            if (($currentQuery === null || $index < $currentQuery)
                && !in_array($message['role'] ?? null, ['system', 'developer'], true)) {
                return $index;
            }
        }

        return null;
    }

    private static function normalizeProfile(string $profile): string
    {
        return match ($profile) {
            'fast', 'short' => 'short',
            'premium', 'detailed' => 'detailed',
            'assistant', 'json', 'estimate_generation', 'normal' => 'normal',
            default => throw new DomainException('ai_token_profile_invalid'),
        };
    }
}
