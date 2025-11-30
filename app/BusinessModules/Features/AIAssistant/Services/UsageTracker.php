<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIUsageStats;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UsageTracker
{
    public function canMakeRequest(int $organizationId): bool
    {
        $module = Module::where('slug', 'ai-assistant')->first();
        
        if (!$module) {
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

        return Cache::remember($cacheKey, 600, function () use ($organizationId, $year, $month) {
            $stats = AIUsageStats::where('organization_id', $organizationId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            return $stats ? $stats->requests_count : 0;
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
        $limit = $module->limits['max_ai_requests_per_month'] ?? 5000;
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
     * Рассчитывает стоимость использования LLM
     * 
     * @param int $tokens Общее количество токенов (для обратной совместимости)
     * @param string $model Название модели
     * @param array|null $responseData Детальная информация о токенах (опционально)
     * @return float Стоимость в рублях
     */
    public function calculateCost(int $tokens, string $model = 'gpt-4o-mini', ?array $responseData = null): float
    {
        // Определяем провайдера по названию модели
        $provider = $this->detectProvider($model);
        
        // Для DeepSeek используем детальную информацию о cache, если доступна
        if ($provider === 'deepseek' && $responseData) {
            return $this->calculateDeepSeekCost($responseData);
        }
        
        // Для других провайдеров используем стандартный расчет
        // Разделяем токены на input и output (примерное соотношение 75/25)
        $inputTokens = (int) ($tokens * 0.75);
        $outputTokens = $tokens - $inputTokens;

        // Цены за 1M токенов в USD
        $pricing = match($provider) {
            'yandex' => [
                'input' => 4.0,    // ~₽400 за 1M токенов = ~$4 (при курсе 100₽/$)
                'output' => 4.0,
            ],
            'openai' => [
                'input' => 0.15,   // GPT-4o-mini: $0.15 за 1M input
                'output' => 0.60,  // GPT-4o-mini: $0.60 за 1M output
            ],
            default => [
                'input' => 0.15,
                'output' => 0.60,
            ],
        };

        $costUsd = ($inputTokens / 1000000 * $pricing['input']) + 
                   ($outputTokens / 1000000 * $pricing['output']);

        // Конвертируем в рубли (курс можно вынести в конфиг)
        $rubPerDollar = 100;
        
        return $costUsd * $rubPerDollar;
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
    protected function detectProvider(string $model): string
    {
        if (str_contains($model, 'deepseek')) {
            return 'deepseek';
        }
        
        if (str_contains($model, 'yandexgpt') || str_contains($model, 'gpt://')) {
            return 'yandex';
        }
        
        if (str_contains($model, 'gpt-') || str_contains($model, 'openai')) {
            return 'openai';
        }
        
        // По умолчанию проверяем текущий провайдер из конфига
        return config('ai-assistant.llm.provider', 'deepseek');
    }
}

