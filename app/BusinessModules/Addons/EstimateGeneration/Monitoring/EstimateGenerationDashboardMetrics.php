<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final class EstimateGenerationDashboardMetrics
{
    /**
     * @param  array<string, mixed>  $sessions
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $queue
     * @return array<string, int|float|string|null>
     */
    public static function fromRows(array $sessions, array $usage, array $queue): array
    {
        $total = self::integer($sessions['sessions_total'] ?? 0);
        $successful = self::integer($sessions['successful_sessions'] ?? 0);
        $applied = self::integer($sessions['applied_sessions'] ?? 0);
        $review = self::integer($sessions['review_sessions'] ?? 0);
        $cost = is_numeric($usage['total_cost'] ?? null) ? round((float) $usage['total_cost'], 8) : null;

        return [
            'sessions_total' => $total,
            'successful_sessions' => $successful,
            'applied_sessions' => $applied,
            'documents_total' => self::integer($sessions['documents_total'] ?? 0),
            'apply_rate' => self::ratio($applied, $successful),
            'review_rate' => self::ratio($review, $successful),
            'average_duration_ms' => self::integer($sessions['average_duration_ms'] ?? 0),
            'p95_duration_ms' => self::integer($sessions['p95_duration_ms'] ?? 0),
            'total_cost' => $cost,
            'cost_per_successful_session' => $cost !== null && $successful > 0 ? round($cost / $successful, 8) : null,
            'cost_per_applied_session' => $cost !== null && $applied > 0 ? round($cost / $applied, 8) : null,
            'currency' => is_string($usage['currency'] ?? null) ? $usage['currency'] : null,
            'running_jobs' => self::integer($queue['running_jobs'] ?? 0),
            'stale_jobs' => self::integer($queue['stale_jobs'] ?? 0),
            'oldest_queue_age_seconds' => self::integer($queue['oldest_queue_age_seconds'] ?? 0),
        ];
    }

    private static function integer(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private static function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 6) : 0.0;
    }
}
