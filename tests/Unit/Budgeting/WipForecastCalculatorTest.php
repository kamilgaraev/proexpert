<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastManualAdjustment;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastSourceAggregate;
use App\BusinessModules\Features\Budgeting\Services\WipForecastCalculator;
use Tests\TestCase;

final class WipForecastCalculatorTest extends TestCase
{
    public function test_calculates_wip_forecast_to_complete_and_margin(): void
    {
        $result = (new WipForecastCalculator())->calculate(
            filters: $this->filters(),
            aggregates: [$this->aggregate()],
            dimensions: $this->dimensions(),
            scenario: ['id' => 'scenario-1', 'name' => 'Базовый'],
            budgetVersion: ['id' => 'budget-1', 'name' => 'БДР'],
            forecastVersion: ['id' => 'forecast-1', 'status' => 'editing'],
            adjustments: [
                new WipForecastManualAdjustment(
                    periodMonth: '2026-01-01',
                    projectId: 10,
                    stageId: null,
                    contractId: 20,
                    estimateItemId: 30,
                    currency: 'RUB',
                    formulaComponent: 'ftc',
                    amount: 50.0,
                    reason: 'Рост стоимости материалов',
                    status: 'approved',
                ),
            ],
            assumptions: [],
            sourceCoverage: [],
            freshness: [],
            meta: ['generated_at' => '2026-01-31T12:00:00+03:00'],
        );

        $metrics = $result['rows'][0]['metrics'];

        $this->assertSame(1000.0, $metrics['bac']);
        $this->assertSame(50.0, $metrics['percent_complete']);
        $this->assertSame(500.0, $metrics['ev']);
        $this->assertSame(400.0, $metrics['pv']);
        $this->assertSame(300.0, $metrics['ac']);
        $this->assertSame(150.0, $metrics['wip']);
        $this->assertSame(150.0, $metrics['wip_total']);
        $this->assertSame(500.0, $metrics['ctc']);
        $this->assertSame(450.0, $metrics['etc']);
        $this->assertSame(500.0, $metrics['ftc']);
        $this->assertSame(800.0, $metrics['eac']);
        $this->assertSame(1200.0, $metrics['forecast_revenue']);
        $this->assertSame(1200.0, $metrics['forecast_revenue_at_completion']);
        $this->assertSame(400.0, $metrics['forecast_gross_margin']);
        $this->assertSame(33.33, $metrics['forecast_margin_percent']);
        $this->assertSame(200.0, $metrics['cash_only_payments_excluded']);
    }

    public function test_cash_only_payment_is_not_included_in_actual_cost(): void
    {
        $result = (new WipForecastCalculator())->calculate(
            filters: $this->filters(),
            aggregates: [$this->aggregate(actualCostAccrual: 125.0, cashOnlyPayments: 900.0)],
            dimensions: $this->dimensions(),
            scenario: null,
            budgetVersion: null,
            forecastVersion: null,
        );

        $metrics = $result['rows'][0]['metrics'];

        $this->assertSame(125.0, $metrics['ac']);
        $this->assertSame(900.0, $metrics['cash_only_payments_excluded']);
        $this->assertSame(575.0, $metrics['eac']);
    }

    public function test_manual_adjustment_without_reason_is_not_applied_to_active_forecast(): void
    {
        $result = (new WipForecastCalculator())->calculate(
            filters: $this->filters(),
            aggregates: [$this->aggregate()],
            dimensions: $this->dimensions(),
            scenario: null,
            budgetVersion: null,
            forecastVersion: ['id' => 'forecast-active', 'status' => 'active'],
            adjustments: [
                new WipForecastManualAdjustment(
                    periodMonth: '2026-01-01',
                    projectId: 10,
                    stageId: null,
                    contractId: 20,
                    estimateItemId: 30,
                    currency: 'RUB',
                    formulaComponent: 'ftc',
                    amount: 100.0,
                    reason: '',
                    status: 'approved',
                ),
            ],
        );

        $metrics = $result['rows'][0]['metrics'];

        $this->assertSame(450.0, $metrics['ftc']);
        $this->assertSame(0.0, $metrics['manual_adjustments']);
    }

    public function test_response_shape_matches_admin_contract(): void
    {
        $result = (new WipForecastCalculator())->calculate(
            filters: $this->filters(),
            aggregates: [$this->aggregate()],
            dimensions: $this->dimensions(),
            scenario: null,
            budgetVersion: null,
            forecastVersion: null,
            assumptions: [['type' => 'management_source_of_truth']],
            sourceCoverage: [['source_type' => 'budget_amount', 'available' => true]],
            meta: ['generated_at' => '2026-01-31T12:00:00+03:00'],
        );

        foreach ([
            'summary',
            'totals_by_currency',
            'rows',
            'formulas',
            'assumptions',
            'source_coverage',
            'freshness',
            'problem_flags',
            'risk_flags',
            'drill_down',
            'actions',
            'meta',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }

        $this->assertSame('/api/v1/admin/budgeting/wip-forecast/drill-down', $result['drill_down']['endpoint']);
        $this->assertArrayHasKey('source_of_truth', $result['meta']);
        $this->assertSame('confirmation_only', $result['meta']['source_of_truth']['bank_1c_edo']);
    }

    private function filters(): WipForecastReportFilters
    {
        return new WipForecastReportFilters(
            organizationId: 1,
            periodStart: '2026-01-01',
            periodEnd: '2026-12-31',
            asOfDate: '2026-01-31',
            forecastVersionId: null,
            forecastVersionUuid: null,
            budgetVersionId: 1,
            budgetVersionUuid: 'budget-1',
            scenarioId: 1,
            scenarioUuid: 'scenario-1',
            projectId: null,
            stageId: null,
            contractId: null,
            estimateItemId: null,
            currency: null,
            groupBy: [
                WipForecastReportFilters::GROUP_PERIOD,
                WipForecastReportFilters::GROUP_PROJECT,
                WipForecastReportFilters::GROUP_CONTRACT,
                WipForecastReportFilters::GROUP_ESTIMATE_ITEM,
                WipForecastReportFilters::GROUP_CURRENCY,
            ],
        );
    }

    private function aggregate(float $actualCostAccrual = 300.0, float $cashOnlyPayments = 200.0): WipForecastSourceAggregate
    {
        return new WipForecastSourceAggregate(
            periodMonth: '2026-01-01',
            projectId: 10,
            stageId: null,
            contractId: 20,
            estimateItemId: 30,
            currency: 'RUB',
            bac: 1000.0,
            plannedValue: 400.0,
            percentComplete: null,
            earnedValueAmount: 500.0,
            approvedActValue: 350.0,
            actualCostAccrual: $actualCostAccrual,
            cashOnlyPayments: $cashOnlyPayments,
            bottomUpEtc: 450.0,
            forecastRevenue: 1200.0,
            sourceTypes: ['budget_amount', 'completed_work', 'warehouse_movement', 'payment_document'],
            problemFlags: [],
            riskFlags: ['cash_only_payment_excluded_from_ac'],
            qualityStatus: 'attention',
            sourceRowsCount: 4,
        );
    }

    private function dimensions(): WipForecastDimensions
    {
        return new WipForecastDimensions(
            projects: [
                10 => ['id' => 10, 'name' => 'Проект'],
            ],
            contracts: [
                20 => ['id' => 20, 'number' => 'Д-20', 'subject' => 'Работы'],
            ],
            estimateItems: [
                30 => ['id' => 30, 'name' => 'Монтаж', 'position_number' => '1'],
            ],
        );
    }
}
