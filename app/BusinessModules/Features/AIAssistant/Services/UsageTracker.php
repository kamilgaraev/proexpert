<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIUsageRecord;
use App\BusinessModules\Features\AIAssistant\Models\AIUsageStats;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UsageTracker
{
    public function canMakeRequest(int $organizationId): bool
    {
        $module = Module::where('slug', 'ai-assistant')->first();

        if (! $module) {
            return false;
        }

        $limit = $module->limits['max_ai_requests_per_month'] ?? 5000;
        $used = $this->getMonthlyUsage($organizationId);

        return $used < $limit;
    }

    public function getMonthlyUsage(int $organizationId): int
    {
        $year = now()->year;
        $month = now()->month;

        $cacheKey = "ai_usage:{$organizationId}:{$year}:{$month}";

        return (int) Cache::remember($cacheKey, 600, function () use ($organizationId, $year, $month): int {
            $stats = AIUsageStats::where('organization_id', $organizationId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            return $stats ? (int) $stats->requests_count : 0;
        });
    }

    public function trackRequest(int $organizationId, User $user, int $tokens, float $costRub): void
    {
        $year = now()->year;
        $month = now()->month;

        $stats = AIUsageStats::getOrCreate($organizationId, $year, $month);
        $stats->incrementUsage($tokens, $costRub);

        $cacheKey = "ai_usage:{$organizationId}:{$year}:{$month}";
        Cache::forget($cacheKey);
    }

    public function getUsageStats(int $organizationId): array
    {
        $module = Module::where('slug', 'ai-assistant')->first();
        $limit = (int) ($module?->limits['max_ai_requests_per_month'] ?? 5000);
        $used = $this->getMonthlyUsage($organizationId);

        $year = now()->year;
        $month = now()->month;

        $stats = AIUsageStats::where('organization_id', $organizationId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return [
            'monthly_limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'percentage_used' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
            'tokens_used' => $stats ? $stats->tokens_used : 0,
            'cost_rub' => $stats ? (float) $stats->cost_rub : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordUsage(
        ?int $organizationId,
        ?int $userId,
        string $provider,
        string $model,
        string $operation,
        int $inputTokens,
        int $outputTokens = 0,
        ?int $totalTokens = null,
        array $metadata = []
    ): ?AIUsageRecord {
        $totalTokens = $totalTokens ?? ($inputTokens + $outputTokens);

        if ($totalTokens <= 0 && $inputTokens <= 0 && $outputTokens <= 0) {
            return null;
        }

        try {
            if (! Schema::hasTable('ai_usage_records')) {
                return null;
            }

            $cost = $this->calculateCostBreakdown(
                $totalTokens,
                $model,
                $inputTokens,
                $outputTokens,
                false,
                $provider
            );

            return AIUsageRecord::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'operation' => $operation,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'total_tokens' => max(0, $totalTokens),
                'input_cost_rub' => $cost['input'],
                'output_cost_rub' => $cost['output'],
                'total_cost_rub' => $cost['total'],
                'currency' => 'RUB',
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            Log::warning('ai.usage.record_failed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'operation' => $operation,
                'exception_class' => $throwable::class,
            ]);

            return null;
        }
    }

    /**
     * Рассчитывает стоимость использования LLM
     *
     * @param  int  $totalTokens  Общее количество токенов (для обратной совместимости)
     * @param  string  $model  Название модели
     * @param  int|null  $inputTokens  Количество входных токенов (если доступно)
     * @param  int|null  $outputTokens  Количество выходных токенов (если доступно)
     * @param  bool  $isAsync  Использовать асинхронный режим для Yandex моделей
     * @return float Стоимость в рублях
     */
    public function calculateCost(
        int $totalTokens,
        string $model,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        bool $isAsync = false,
        ?string $providerName = null
    ): float {
        return $this->calculateCostBreakdown(
            $totalTokens,
            $model,
            $inputTokens,
            $outputTokens,
            $isAsync,
            $providerName
        )['total'];
    }

    /**
     * @return array{input: float, output: float, total: float}
     */
    public function calculateCostBreakdown(
        int $totalTokens,
        string $model,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        bool $isAsync = false,
        ?string $providerName = null
    ): array {
        // Определяем провайдера по названию модели
        $provider = $this->detectProvider($model, $providerName);

        // Если input/output токены не указаны, используем примерное соотношение 75/25
        $inputTokens = $inputTokens ?? (int) ($totalTokens * 0.75);
        $outputTokens = $outputTokens ?? ($totalTokens - $inputTokens);

        // Цены за 1000 токенов в рублях (для Yandex) или за 1M токенов в USD (для других)
        if ($provider === 'yandex') {
            // Проверяем, это Alice AI или обычный YandexGPT
            $isAliceAI = str_contains($model, 'aliceai');

            if ($isAliceAI) {
                // Alice AI LLM цены
                if ($isAsync) {
                    // Асинхронный режим: 0.25₽ за 1K input, 1.00₽ за 1K output
                    $inputPricePerK = 0.25;
                    $outputPricePerK = 1.00;
                } else {
                    // Синхронный режим: 0.50₽ за 1K input, 2.00₽ за 1K output
                    $inputPricePerK = 0.50;
                    $outputPricePerK = 2.00;
                }

                return $this->costBreakdown(
                    $inputTokens / 1000 * $inputPricePerK,
                    $outputTokens / 1000 * $outputPricePerK
                );
            } else {
                // Обычный YandexGPT: ~₽400 за 1M токенов (входные и выходные одинаково)
                $pricePerMillion = 400;

                return $this->costBreakdown(
                    $inputTokens / 1000000 * $pricePerMillion,
                    $outputTokens / 1000000 * $pricePerMillion
                );
            }
        }

        // Для DeepSeek используем специальный расчет (если переданы детальные данные)
        if ($provider === 'timeweb') {
            $pricing = $this->timewebPricing($model);

            return $this->costBreakdown(
                $inputTokens / 1000000 * $pricing['input'],
                $outputTokens / 1000000 * $pricing['output']
            );
        }

        if ($provider === 'deepseek') {
            // DeepSeek цены в USD за 1M токенов
            $inputCacheMissPrice = 0.28;  // $0.28 за 1M (cache miss)
            $outputPrice = 0.42;           // $0.42 за 1M (output)

            // Без информации о cache считаем все как cache miss
            $rubPerDollar = 100;

            return $this->costBreakdown(
                $inputTokens / 1000000 * $inputCacheMissPrice * $rubPerDollar,
                $outputTokens / 1000000 * $outputPrice * $rubPerDollar
            );
        }

        // OpenAI и другие провайдеры
        $pricing = match ($provider) {
            'openai' => [
                'input' => 0.15,   // GPT-4o-mini: $0.15 за 1M input
                'output' => 0.60,  // GPT-4o-mini: $0.60 за 1M output
            ],
            default => [
                'input' => 0.15,
                'output' => 0.60,
            ],
        };

        $rubPerDollar = 100;

        return $this->costBreakdown(
            $inputTokens / 1000000 * $pricing['input'] * $rubPerDollar,
            $outputTokens / 1000000 * $pricing['output'] * $rubPerDollar
        );
    }

    /**
     * Рассчитывает стоимость для DeepSeek с учетом cache hit/miss
     */
    protected function calculateDeepSeekCost(array $responseData): float
    {
        // Актуальные цены DeepSeek (с официального сайта)
        $inputCacheHitPrice = 0.028;   // $0.028 за 1M токенов (cache hit)
        $inputCacheMissPrice = 0.28;   // $0.28 за 1M токенов (cache miss)
        $outputPrice = 0.42;            // $0.42 за 1M токенов (output)

        // Извлекаем информацию о токенах
        $promptCacheHitTokens = $responseData['prompt_cache_hit_tokens'] ?? 0;
        $promptCacheMissTokens = $responseData['prompt_cache_miss_tokens'] ?? 0;
        $completionTokens = $responseData['completion_tokens'] ?? 0;

        // Если нет детальной информации о cache, используем общее количество prompt токенов как cache miss
        if ($promptCacheHitTokens == 0 && $promptCacheMissTokens == 0) {
            $promptTokens = $responseData['prompt_tokens'] ?? 0;
            $promptCacheMissTokens = $promptTokens;
        }

        // Рассчитываем стоимость
        $inputCostUsd = ($promptCacheHitTokens / 1000000 * $inputCacheHitPrice) +
                       ($promptCacheMissTokens / 1000000 * $inputCacheMissPrice);

        $outputCostUsd = ($completionTokens / 1000000 * $outputPrice);

        $totalCostUsd = $inputCostUsd + $outputCostUsd;

        // Конвертируем в рубли
        $rubPerDollar = 100;

        return $totalCostUsd * $rubPerDollar;
    }

    /**
     * Определяет провайдера по названию модели
     */
    protected function detectProvider(string $model, ?string $providerName = null): string
    {
        if (is_string($providerName) && trim($providerName) !== '') {
            return strtolower(trim($providerName));
        }

        $normalizedModel = strtolower($model);

        if (str_contains($normalizedModel, 'deepseek')) {
            return 'deepseek';
        }

        // Yandex модели (включая Alice AI)
        if (str_contains($normalizedModel, 'yandexgpt') ||
            str_contains($normalizedModel, 'aliceai') ||
            str_contains($normalizedModel, 'gpt://')) {
            return 'yandex';
        }

        if (
            str_contains($normalizedModel, 'gemini') ||
            str_contains($normalizedModel, 'claude') ||
            str_contains($normalizedModel, 'qwen') ||
            str_contains($normalizedModel, 'grok')
        ) {
            return 'timeweb';
        }

        if (str_contains($normalizedModel, 'gpt-') || str_contains($normalizedModel, 'openai')) {
            return 'openai';
        }

        // По умолчанию проверяем текущий провайдер из конфига
        return (string) config('ai-assistant.llm.provider', 'deepseek');
    }

    /**
     * @return array{input: float, output: float}
     */
    protected function timewebPricing(string $model): array
    {
        $inputOverride = config('ai-assistant.llm.timeweb.input_price_per_million');
        $outputOverride = config('ai-assistant.llm.timeweb.output_price_per_million');

        if (is_numeric($inputOverride) && is_numeric($outputOverride)) {
            return [
                'input' => (float) $inputOverride,
                'output' => (float) $outputOverride,
            ];
        }

        $normalized = strtolower(str_replace(['_', ' ', '/', ':'], '-', $model));

        $pricing = [
            'gemini-3.1-flash-lite' => ['input' => 34.0, 'output' => 203.0],
            'gemini-3-flash-preview' => ['input' => 68.0, 'output' => 405.0],
            'gemini-3-pro-preview' => ['input' => 270.0, 'output' => 1620.0],
            'gemini-3.1-pro-preview' => ['input' => 270.0, 'output' => 1620.0],
            'gemini-2.5-flash-lite' => ['input' => 14.0, 'output' => 54.0],
            'gemini-2.5-flash' => ['input' => 41.0, 'output' => 338.0],
            'gemini-2.5-pro' => ['input' => 169.0, 'output' => 1350.0],
            'deepseek-v4-flash-thinking' => ['input' => 18.9, 'output' => 37.8],
            'deepseek-v3.2' => ['input' => 74.0, 'output' => 296.0],
            'text-embedding-3-large' => ['input' => 45.0, 'output' => 0.0],
            'text-embedding-3-small' => ['input' => 3.0, 'output' => 0.0],
            'text-embedding-005' => ['input' => 13.5, 'output' => 0.0],
            'gemini-embedding-2' => ['input' => 27.0, 'output' => 0.0],
            'qwen-text-embedding-v4' => ['input' => 9.0, 'output' => 0.0],
            'qwen-3.6-plus' => ['input' => 68.0, 'output' => 405.0],
            'qwen-3.5-plus' => ['input' => 60.0, 'output' => 60.0],
            'qwen-3.5-flash' => ['input' => 60.0, 'output' => 60.0],
            'claude-4.6-sonnet' => ['input' => 405.0, 'output' => 2025.0],
            'claude-4.5-sonnet' => ['input' => 405.0, 'output' => 2025.0],
            'claude-4.5-haiku' => ['input' => 135.0, 'output' => 1080.0],
            'gpt-5-mini' => ['input' => 34.0, 'output' => 270.0],
            'gpt-5-nano' => ['input' => 7.0, 'output' => 54.0],
            'gpt-5' => ['input' => 169.0, 'output' => 1350.0],
            'gpt-4.1' => ['input' => 270.0, 'output' => 1080.0],
        ];

        foreach ($pricing as $modelKey => $prices) {
            if (str_contains($normalized, $modelKey)) {
                return $prices;
            }
        }

        return ['input' => 34.0, 'output' => 203.0];
    }

    /**
     * @return array{input: float, output: float, total: float}
     */
    private function costBreakdown(float $inputCost, float $outputCost): array
    {
        return [
            'input' => $inputCost,
            'output' => $outputCost,
            'total' => $inputCost + $outputCost,
        ];
    }
}
