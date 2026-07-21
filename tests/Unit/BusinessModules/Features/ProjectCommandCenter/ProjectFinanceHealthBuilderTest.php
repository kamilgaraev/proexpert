<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectFinanceHealthBuilder;
use App\Services\Analytics\EVMService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectFinanceHealthBuilderTest extends TestCase
{
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
