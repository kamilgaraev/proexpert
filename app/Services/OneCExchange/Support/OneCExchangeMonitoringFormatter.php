<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Support;

final class OneCExchangeMonitoringFormatter
{
    private const BACKLOG_STATUSES = [
        'pending',
        'queued',
        'retry_scheduled',
    ];

    public function summary(
        bool $configured,
        bool $deliveryEnabled,
        array $statusCounts,
        int $staleProcessingCount,
        ?int $oldestPendingAgeMinutes,
        ?string $lastSuccessAt,
        ?string $lastFailureAt,
        int $windowTotalCount,
        int $windowSuccessCount,
        int $windowFailureCount,
        int $windowRetryCount,
        ?float $avgDurationMs,
        ?float $p95DurationMs,
    ): array {
        $pendingCount = $this->count($statusCounts, 'pending');
        $queuedCount = $this->count($statusCounts, 'queued');
        $processingCount = $this->count($statusCounts, 'processing');
        $retryScheduledCount = $this->count($statusCounts, 'retry_scheduled');
        $failedCount = $this->count($statusCounts, 'failed');
        $deadLetterCount = $this->count($statusCounts, 'dead_letter');
        $requiresMappingCount = $this->count($statusCounts, 'requires_mapping');
        $backlogCount = $this->backlogCount($statusCounts);
        $outcomeCount = $windowSuccessCount + $windowFailureCount;

        return [
            'health' => $this->health(
                configured: $configured,
                deliveryEnabled: $deliveryEnabled,
                backlogCount: $backlogCount,
                failedCount: $failedCount,
                deadLetterCount: $deadLetterCount,
                requiresMappingCount: $requiresMappingCount,
                staleProcessingCount: $staleProcessingCount,
            ),
            'configured' => $configured,
            'delivery_enabled' => $deliveryEnabled,
            'pending_count' => $pendingCount,
            'queued_count' => $queuedCount,
            'processing_count' => $processingCount,
            'retry_scheduled_count' => $retryScheduledCount,
            'failed_count' => $failedCount,
            'dead_letter_count' => $deadLetterCount,
            'requires_mapping_count' => $requiresMappingCount,
            'stale_processing_count' => $staleProcessingCount,
            'backlog_count' => $backlogCount,
            'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
            'last_success_at' => $lastSuccessAt,
            'last_failure_at' => $lastFailureAt,
            'window_total_count' => $windowTotalCount,
            'window_success_count' => $windowSuccessCount,
            'window_failure_count' => $windowFailureCount,
            'window_retry_count' => $windowRetryCount,
            'success_rate' => $outcomeCount > 0 ? $this->percent($windowSuccessCount, $outcomeCount) : 100.0,
            'error_rate' => $outcomeCount > 0 ? $this->percent($windowFailureCount, $outcomeCount) : 0.0,
            'retry_rate' => $windowTotalCount > 0 ? $this->percent($windowRetryCount, $windowTotalCount) : 0.0,
            'avg_duration_ms' => $this->duration($avgDurationMs),
            'p95_duration_ms' => $this->duration($p95DurationMs),
        ];
    }

    private function health(
        bool $configured,
        bool $deliveryEnabled,
        int $backlogCount,
        int $failedCount,
        int $deadLetterCount,
        int $requiresMappingCount,
        int $staleProcessingCount,
    ): string {
        if ($deadLetterCount > 0 || $staleProcessingCount > 0) {
            return 'critical';
        }

        if (!$configured || !$deliveryEnabled || $failedCount > 0 || $requiresMappingCount > 0 || $backlogCount > 0) {
            return 'warning';
        }

        return 'ok';
    }

    private function backlogCount(array $statusCounts): int
    {
        return array_reduce(
            self::BACKLOG_STATUSES,
            fn (int $sum, string $status): int => $sum + $this->count($statusCounts, $status),
            0
        );
    }

    private function count(array $statusCounts, string $status): int
    {
        return (int) ($statusCounts[$status] ?? 0);
    }

    private function percent(int $value, int $total): float
    {
        return round(($value / $total) * 100, 1);
    }

    private function duration(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
