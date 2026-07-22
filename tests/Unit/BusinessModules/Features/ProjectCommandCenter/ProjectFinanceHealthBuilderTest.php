<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectFinanceHealthBuilder;
use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterPeriod;
use App\Services\Analytics\EVMService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectFinanceHealthBuilderTest extends TestCase
{
    public function test_it_does_not_label_full_project_evm_and_margin_as_month_facts(): void
    {
        $period = ProjectCommandCenterPeriod::resolve('month', null, null, null, null, CarbonImmutable::parse('2026-07-21'));
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1000, 'ac' => 500, 'eac' => 1200],
            'contracted_revenue' => 1500,
            'payments' => [],
        ], CarbonImmutable::parse('2026-07-21'), $period)->toArray();

        self::assertFalse($result['margin']['available']);
        self::assertSame('project_command_center.finance.period_metrics_unavailable', $result['margin']['reason_key']);
        self::assertFalse($result['evm']['available']);
        self::assertNull($result['evm']['actual_cost']);
        self::assertSame('project_command_center.finance.period_metrics_unavailable', $result['evm']['reason_key']);
    }

    public function test_it_builds_plan_fact_forecast_and_dated_cash_flow(): void
    {
        $result = $this->builder()->fromFacts([
            'contracted_revenue' => 1_500_000,
            'metrics' => ['bac' => 1_000_000, 'ac' => 400_000, 'eac' => 1_200_000, 'spi' => 0.91],
            'payments' => [
                ['direction' => 'incoming', 'amount' => 600_000, 'due_at' => '2026-08-10'],
                ['direction' => 'outgoing', 'amount' => 150_000, 'due_at' => '2026-09-15'],
            ],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertTrue($result['available']);
        self::assertSame(300000.0, $result['margin']['forecast_profit']);
        self::assertSame(20.0, $result['margin']['forecast_margin_percent']);
        self::assertSame(200000.0, $result['evm']['deviation']);
        self::assertTrue($result['cash_flow']['available']);
        self::assertSame(600000.0, $result['cash_flow']['accounts_receivable']);
        self::assertSame(150000.0, $result['cash_flow']['accounts_payable']);
        self::assertSame(600000.0, $result['cash_flow']['projections'][0]['net']);
        self::assertSame(-150000.0, $result['cash_flow']['projections'][1]['net']);
    }

    public function test_it_does_not_invent_margin_without_contracted_revenue(): void
    {
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'ac' => 500, 'eac' => 1_100],
            'payments' => [],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertFalse($result['margin']['available']);
        self::assertSame('project_command_center.finance.contracted_revenue_unavailable', $result['margin']['reason_key']);
        self::assertFalse($result['cash_flow']['available']);
        self::assertSame('project_command_center.finance.payment_schedule_unavailable', $result['cash_flow']['reason_key']);
    }

    public function test_it_keeps_all_outstanding_documents_in_receivables_and_payables(): void
    {
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'ac' => 500, 'eac' => 1_100],
            'payments' => [
                ['direction' => 'incoming', 'amount' => 700, 'due_at' => '2026-08-10'],
                ['direction' => 'incoming', 'amount' => 300, 'due_at' => null],
                ['direction' => 'outgoing', 'amount' => 250, 'due_at' => null],
            ],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertSame(1000.0, $result['cash_flow']['accounts_receivable']);
        self::assertSame(250.0, $result['cash_flow']['accounts_payable']);
        self::assertSame(700.0, $result['cash_flow']['projections'][0]['incoming']);
    }

    public function test_it_keeps_cash_flow_available_when_only_dated_documents_are_beyond_the_horizon(): void
    {
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'ac' => 500, 'eac' => 1_100],
            'payments' => [
                ['direction' => 'incoming', 'amount' => 700, 'due_at' => '2026-12-01'],
                ['direction' => 'outgoing', 'amount' => 250, 'due_at' => '2026-12-01'],
            ],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertTrue($result['cash_flow']['available']);
        self::assertSame(700.0, $result['cash_flow']['accounts_receivable']);
        self::assertSame(250.0, $result['cash_flow']['accounts_payable']);

        foreach ($result['cash_flow']['projections'] as $projection) {
            self::assertSame(0.0, $projection['incoming']);
            self::assertSame(0.0, $projection['outgoing']);
            self::assertSame(0.0, $projection['net']);
        }
    }

    public function test_it_keeps_historical_overdue_documents_out_of_forward_cash_flow_projection(): void
    {
        $historicalPeriod = ProjectCommandCenterPeriod::resolve(
            'custom',
            '2026-01-01',
            '2026-01-31',
            null,
            null,
            CarbonImmutable::parse('2026-07-21'),
        );

        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'ac' => 500, 'eac' => 1_100],
            'payments' => [
                ['direction' => 'incoming', 'amount' => 700, 'due_at' => '2026-01-15'],
                ['direction' => 'outgoing', 'amount' => 250, 'due_at' => '2026-08-10'],
            ],
        ], CarbonImmutable::parse('2026-07-21'), $historicalPeriod)->toArray();

        self::assertSame('2026-07-21', $result['cash_flow']['as_of']);
        self::assertSame(90, $result['cash_flow']['horizon_days']);
        self::assertSame(700.0, $result['cash_flow']['overdue']['incoming']);
        self::assertSame(0.0, $result['cash_flow']['projections'][0]['incoming']);
        self::assertSame(-250.0, $result['cash_flow']['projections'][0]['net']);
    }

    public function test_it_places_documents_due_on_as_of_date_in_first_cash_flow_projection(): void
    {
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'ac' => 500, 'eac' => 1_100],
            'payments' => [
                ['direction' => 'incoming', 'amount' => 700, 'due_at' => '2026-07-21'],
                ['direction' => 'outgoing', 'amount' => 250, 'due_at' => '2026-07-21'],
                ['direction' => 'incoming', 'amount' => 100, 'due_at' => '2026-07-20'],
                ['direction' => 'outgoing', 'amount' => 50, 'due_at' => '2026-07-20'],
            ],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertSame(100.0, $result['cash_flow']['overdue']['incoming']);
        self::assertSame(50.0, $result['cash_flow']['overdue']['outgoing']);
        self::assertSame(700.0, $result['cash_flow']['projections'][0]['incoming']);
        self::assertSame(250.0, $result['cash_flow']['projections'][0]['outgoing']);
        self::assertSame(450.0, $result['cash_flow']['projections'][0]['net']);
    }

    public function test_it_marks_missing_actual_costs_explicitly(): void
    {
        $result = $this->builder()->fromFacts([
            'metrics' => ['bac' => 1_000, 'eac' => 1_100],
            'payments' => [],
        ], CarbonImmutable::parse('2026-07-21'))->toArray();

        self::assertFalse($result['evm']['available']);
        self::assertSame('project_command_center.finance.actual_cost_unavailable', $result['evm']['reason_key']);
        self::assertFalse($result['data_completeness']['actual_costs']['available']);
    }

    private function builder(): ProjectFinanceHealthBuilder
    {
        return new ProjectFinanceHealthBuilder($this->createMock(EVMService::class));
    }
}
