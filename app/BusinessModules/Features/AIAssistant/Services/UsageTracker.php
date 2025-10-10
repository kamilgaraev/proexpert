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

    public function calculateCost(int $tokens, string $model = 'gpt-4o-mini'): float
    {
        $inputTokens = (int) ($tokens * 0.75);
        $outputTokens = $tokens - $inputTokens;

        $costPerMillionInput = 0.15;
        $costPerMillionOutput = 0.60;

        $costUsd = ($inputTokens / 1000000 * $costPerMillionInput) + 
                   ($outputTokens / 1000000 * $costPerMillionOutput);

        $rubPerDollar = 100;
        
        return $costUsd * $rubPerDollar;
    }
}

