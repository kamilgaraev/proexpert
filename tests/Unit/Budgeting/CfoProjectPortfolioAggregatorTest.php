<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\Services\CfoProjectPortfolioAggregator;
use PHPUnit\Framework\TestCase;

final class CfoProjectPortfolioAggregatorTest extends TestCase
{
    public function test_builds_portfolio_kpis_from_margin_wip_budget_deviations_and_cash_flow_signals(): void
    {
        $result = (new CfoProjectPortfolioAggregator())->build(
            filters: $this->filters(),
            projects: [
                7 => [
                    'id' => 7,
                    'name' => 'Business Center',
                    'status' => 'active',
                    'project_type' => 'commercial',
                    'project_manager' => ['id' => 31, 'name' => 'Иван Петров'],
                ],
                8 => [
                    'id' => 8,
                    'name' => 'Warehouse',
                    'status' => 'paused',
                    'project_type' => 'industrial',
                    'project_manager' => null,
                ],
            ],
            marginReport: [
                'summary' => ['problem_flags' => ['partial_analytics'], 'risk_flags' => []],
                'rows' => [[
                    'project' => ['id' => 7, 'name' => 'Business Center'],
                    'currency' => 'RUB',
                    'plan' => ['revenue' => 160000.0, 'cost' => 120000.0, 'gross_margin' => 40000.0],
                    'forecast' => ['revenue' => 155000.0, 'cost' => 130000.0, 'gross_margin' => 25000.0],
                    'actual' => ['revenue' => 150000.0, 'cost' => 110000.0, 'gross_margin' => 40000.0],
                    'variance' => ['gross_margin' => 0.0],
                    'problem_flags' => [],
                    'risk_flags' => [],
                    'quality_status' => 'actual',
                    'drill_down_key' => 'margin-key',
                ]],
            ],
            wipReport: [
                'summary' => ['problem_flags' => [], 'risk_flags' => ['manual_adjustment']],
                'freshness' => ['status' => 'attention'],
                'rows' => [[
                    'project' => ['id' => 7, 'name' => 'Business Center'],
                    'currency' => 'RUB',
                    'metrics' => [
                        'wip_total' => 12000.0,
                        'ftc' => 30000.0,
                        'eac' => 140000.0,
                        'ctc' => 45000.0,
                        'forecast_revenue_at_completion' => 155000.0,
                        'forecast_gross_margin' => 15000.0,
                    ],
                    'problem_flags' => [],
                    'risk_flags' => ['manual_adjustment'],
                    'quality_status' => 'attention',
                    'drill_down_key' => 'wip-key',
                ]],
            ],
            planFactItems: [[
                'project' => ['id' => 7, 'name' => 'Business Center'],
                'currency' => 'RUB',
                'variance_amount' => -25000.0,
                'risk_level' => 'critical',
                'drill_down_key' => 'plan-fact-key',
            ]],
            calendarItems: [
                new PaymentCalendarItem(
                    sourceType: 'payment_document',
                    sourceId: 10,
                    cashFlowKey: 'out-10',
                    organizationId: 42,
                    date: '2026-06-15',
                    originalDate: null,
                    direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
                    amount: 70000.0,
                    remainingAmount: 70000.0,
                    currency: 'RUB',
                    bucket: PaymentCalendarItem::BUCKET_BUDGET_PLAN,
                    status: 'planned',
                    projectId: 7,
                    probability: 1.0,
                ),
                new PaymentCalendarItem(
                    sourceType: 'payment_document',
                    sourceId: 11,
                    cashFlowKey: 'in-11',
                    organizationId: 42,
                    date: '2026-06-16',
                    originalDate: null,
                    direction: PaymentCalendarItem::DIRECTION_INFLOW,
                    amount: 50000.0,
                    remainingAmount: 50000.0,
                    currency: 'RUB',
                    bucket: PaymentCalendarItem::BUCKET_BUDGET_PLAN,
                    status: 'planned',
                    projectId: 7,
                    probability: 1.0,
                ),
            ],
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 5,
        );

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['summary']['projects_count']);
        $this->assertSame(1, $result['summary']['active_projects_count']);
        $this->assertSame(1, $result['summary']['cash_gap_projects_count']);
        $this->assertSame(1, $result['summary']['budget_deviation_projects_count']);
        $this->assertSame(1, $result['summary']['top_problem_projects_count']);
        $this->assertSame('attention', $result['summary']['freshness_status']);
        $this->assertSame(150000.0, $result['summary']['by_currency']['RUB']['revenue']);
        $this->assertSame(110000.0, $result['summary']['by_currency']['RUB']['cost']);
        $this->assertSame(40000.0, $result['summary']['by_currency']['RUB']['gross_margin']);
        $this->assertSame(12000.0, $result['summary']['by_currency']['RUB']['wip_total']);
        $this->assertSame(30000.0, $result['summary']['by_currency']['RUB']['ftc']);
        $this->assertSame(-20000.0, $result['summary']['by_currency']['RUB']['cash_gap_signal']);
        $this->assertContains('budget_deviation', $result['summary']['problem_flags']);
        $this->assertContains('cash_gap_risk', $result['summary']['risk_flags']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(7, $result['items'][0]['project']['id']);
        $this->assertSame('critical', $result['items'][0]['budget_deviation']['risk_level']);
        $this->assertSame('/budgeting/project-margin?project_id=7', $result['items'][0]['drill_down']['href']);
    }

    public function test_empty_project_scope_stays_available_without_problem_items(): void
    {
        $result = (new CfoProjectPortfolioAggregator())->build(
            filters: $this->filters(),
            projects: [],
            marginReport: ['rows' => []],
            wipReport: ['rows' => []],
            planFactItems: [],
            calendarItems: [],
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 5,
        );

        $this->assertTrue($result['available']);
        $this->assertSame(0, $result['summary']['projects_count']);
        $this->assertSame(0, $result['summary']['top_problem_projects_count']);
        $this->assertSame([], $result['items']);
    }

    public function test_wip_forecast_revenue_at_completion_feeds_forecast_revenue_metric(): void
    {
        $result = (new CfoProjectPortfolioAggregator())->build(
            filters: $this->filters(),
            projects: [
                7 => [
                    'id' => 7,
                    'name' => 'Business Center',
                    'status' => 'active',
                ],
            ],
            marginReport: ['rows' => []],
            wipReport: [
                'rows' => [[
                    'project' => ['id' => 7, 'name' => 'Business Center'],
                    'currency' => 'RUB',
                    'metrics' => [
                        'forecast_revenue_at_completion' => 155000.0,
                        'forecast_gross_margin' => 25000.0,
                    ],
                    'risk_flags' => ['manual_adjustment'],
                ]],
            ],
            planFactItems: [],
            calendarItems: [],
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 5,
        );

        $this->assertSame(155000.0, $result['summary']['by_currency']['RUB']['forecast_revenue']);
        $this->assertSame(155000.0, $result['items'][0]['metrics']['forecast_revenue']);
    }

    private function filters(): CfoCommandCenterFilters
    {
        return new CfoCommandCenterFilters(
            organizationId: 42,
            periodStart: '2026-06-01',
            periodEnd: '2026-06-30',
            itemLimit: 5,
        );
    }
}
