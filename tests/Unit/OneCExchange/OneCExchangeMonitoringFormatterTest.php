<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\Support\OneCExchangeMonitoringFormatter;
use PHPUnit\Framework\TestCase;

final class OneCExchangeMonitoringFormatterTest extends TestCase
{
    public function test_summary_marks_critical_exchange_and_calculates_rates(): void
    {
        $formatter = new OneCExchangeMonitoringFormatter();

        $summary = $formatter->summary(
            configured: true,
            deliveryEnabled: true,
            statusCounts: [
                'pending' => 3,
                'queued' => 2,
                'processing' => 1,
                'retry_scheduled' => 1,
                'completed' => 12,
                'failed' => 2,
                'dead_letter' => 1,
            ],
            staleProcessingCount: 1,
            oldestPendingAgeMinutes: 44,
            lastSuccessAt: '2026-06-07T07:20:00.000000Z',
            lastFailureAt: '2026-06-07T08:10:00.000000Z',
            windowTotalCount: 20,
            windowSuccessCount: 12,
            windowFailureCount: 3,
            windowRetryCount: 5,
            avgDurationMs: 840.4,
            p95DurationMs: 2100.8,
        );

        self::assertSame('critical', $summary['health']);
        self::assertTrue($summary['configured']);
        self::assertTrue($summary['delivery_enabled']);
        self::assertSame(3, $summary['pending_count']);
        self::assertSame(2, $summary['queued_count']);
        self::assertSame(1, $summary['processing_count']);
        self::assertSame(1, $summary['retry_scheduled_count']);
        self::assertSame(2, $summary['failed_count']);
        self::assertSame(1, $summary['dead_letter_count']);
        self::assertSame(1, $summary['stale_processing_count']);
        self::assertSame(6, $summary['backlog_count']);
        self::assertSame(44, $summary['oldest_pending_age_minutes']);
        self::assertSame('2026-06-07T07:20:00.000000Z', $summary['last_success_at']);
        self::assertSame('2026-06-07T08:10:00.000000Z', $summary['last_failure_at']);
        self::assertSame(80.0, $summary['success_rate']);
        self::assertSame(20.0, $summary['error_rate']);
        self::assertSame(25.0, $summary['retry_rate']);
        self::assertSame(840, $summary['avg_duration_ms']);
        self::assertSame(2101, $summary['p95_duration_ms']);
    }

    public function test_summary_defaults_to_ok_for_clean_exchange_without_window_events(): void
    {
        $formatter = new OneCExchangeMonitoringFormatter();

        $summary = $formatter->summary(
            configured: true,
            deliveryEnabled: true,
            statusCounts: [
                'pending' => 0,
                'queued' => 0,
                'processing' => 0,
                'retry_scheduled' => 0,
                'completed' => 0,
                'failed' => 0,
                'dead_letter' => 0,
            ],
            staleProcessingCount: 0,
            oldestPendingAgeMinutes: null,
            lastSuccessAt: null,
            lastFailureAt: null,
            windowTotalCount: 0,
            windowSuccessCount: 0,
            windowFailureCount: 0,
            windowRetryCount: 0,
            avgDurationMs: null,
            p95DurationMs: null,
        );

        self::assertSame('ok', $summary['health']);
        self::assertSame(100.0, $summary['success_rate']);
        self::assertSame(0.0, $summary['error_rate']);
        self::assertSame(0.0, $summary['retry_rate']);
        self::assertNull($summary['avg_duration_ms']);
        self::assertNull($summary['p95_duration_ms']);
    }

    public function test_summary_warns_when_delivery_is_disabled(): void
    {
        $formatter = new OneCExchangeMonitoringFormatter();

        $summary = $formatter->summary(
            configured: true,
            deliveryEnabled: false,
            statusCounts: [],
            staleProcessingCount: 0,
            oldestPendingAgeMinutes: null,
            lastSuccessAt: null,
            lastFailureAt: null,
            windowTotalCount: 0,
            windowSuccessCount: 0,
            windowFailureCount: 0,
            windowRetryCount: 0,
            avgDurationMs: null,
            p95DurationMs: null,
        );

        self::assertSame('warning', $summary['health']);
    }
}
