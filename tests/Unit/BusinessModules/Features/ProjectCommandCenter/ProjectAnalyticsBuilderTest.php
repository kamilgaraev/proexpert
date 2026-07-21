<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectAnalyticsBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectAnalyticsBuilderTest extends TestCase
{
    public function test_it_builds_only_truthful_project_scoped_analytics(): void
    {
        $analytics = (new ProjectAnalyticsBuilder())->fromFacts(
            finance: [
                'available' => true,
                'evm' => [
                    'available' => true,
                    'plan_total_cost' => 1_000.0,
                    'actual_cost' => 400.0,
                    'forecast_total_cost' => 1_200.0,
                ],
                'cash_flow' => [
                    'available' => true,
                    'projections' => [
                        ['days' => 30, 'incoming' => 600.0, 'outgoing' => 100.0, 'net' => 500.0],
                        ['days' => 60, 'incoming' => 0.0, 'outgoing' => 200.0, 'net' => -200.0],
                    ],
                ],
            ],
            asOf: CarbonImmutable::parse('2026-07-21T12:00:00+03:00'),
        );

        self::assertTrue($analytics['plan_vs_fact']['available']);
        self::assertSame(['2026-07-21'], $analytics['plan_vs_fact']['labels']);
        self::assertSame([1000.0], $analytics['plan_vs_fact']['series']['plan']);
        self::assertSame([400.0], $analytics['plan_vs_fact']['series']['fact']);
        self::assertSame([1200.0], $analytics['plan_vs_fact']['series']['forecast']);
        self::assertSame(['30', '60'], $analytics['cash_flow']['labels']);
        self::assertSame([500.0, -200.0], $analytics['cash_flow']['series']['net']);
        self::assertFalse($analytics['risk_trend']['available']);
        self::assertSame('project_command_center.analytics.risk_trend_history_unavailable', $analytics['risk_trend']['reason_key']);
        self::assertArrayNotHasKey('labels', $analytics['risk_trend']);
        self::assertSame(['actual_cost', 'forecast_remaining_cost'], $analytics['cost_outlook']['labels']);
        self::assertSame([400.0, 800.0], $analytics['cost_outlook']['series']['amount']);
        self::assertSame('project_command_center.analytics.cost_outlook', $analytics['cost_outlook']['title_key']);
        self::assertFalse($analytics['work_progress']['available']);
        self::assertSame('project_command_center.analytics.work_progress_history_unavailable', $analytics['work_progress']['reason_key']);
    }

    public function test_it_keeps_financial_analytics_unavailable_without_numerical_or_indirect_leakage(): void
    {
        $analytics = (new ProjectAnalyticsBuilder())->fromFacts(
            finance: ['available' => false, 'reason_key' => 'project_command_center.finance.access_restricted'],
            asOf: CarbonImmutable::parse('2026-07-21T12:00:00+03:00'),
        );

        foreach (['plan_vs_fact', 'cash_flow', 'cost_outlook'] as $dataset) {
            self::assertFalse($analytics[$dataset]['available']);
            self::assertSame('project_command_center.finance.access_restricted', $analytics[$dataset]['reason_key']);
            self::assertArrayNotHasKey('labels', $analytics[$dataset]);
            self::assertArrayNotHasKey('series', $analytics[$dataset]);
        }

        self::assertFalse($analytics['work_progress']['available']);
        self::assertFalse($analytics['risk_trend']['available']);
    }

    public function test_it_returns_empty_series_when_project_has_no_observations(): void
    {
        $analytics = (new ProjectAnalyticsBuilder())->fromFacts(
            finance: [
                'available' => true,
                'evm' => ['available' => false, 'reason_key' => 'project_command_center.finance.actual_cost_unavailable'],
                'cash_flow' => ['available' => false, 'reason_key' => 'project_command_center.finance.payment_schedule_unavailable'],
            ],
            asOf: CarbonImmutable::parse('2026-07-21T12:00:00+03:00'),
        );

        self::assertFalse($analytics['plan_vs_fact']['available']);
        self::assertFalse($analytics['cash_flow']['available']);

        self::assertFalse($analytics['cost_outlook']['available']);

        foreach (['risk_trend', 'work_progress'] as $dataset) {
            self::assertFalse($analytics[$dataset]['available']);
        }
    }
}
