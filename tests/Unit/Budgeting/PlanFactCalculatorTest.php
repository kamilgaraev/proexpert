<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceAggregate;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use PHPUnit\Framework\TestCase;

final class PlanFactCalculatorTest extends TestCase
{
    public function test_calculates_rows_and_currency_totals_without_mixing_currencies(): void
    {
        $result = (new PlanFactCalculator())->calculate(
            filters: $this->filters(),
            aggregates: [
                new PlanFactSourceAggregate('budget_amount', '2026-01', 1, 10, 100, null, 'RUB', 'outflow', 1000.0, 950.0),
                new PlanFactSourceAggregate('payment_transaction', '2026-01', 1, 10, 100, null, 'RUB', 'outflow', actualAmount: 1120.0),
                new PlanFactSourceAggregate('budget_limit_reservation', '2026-01', 1, 10, 100, null, 'RUB', 'outflow', committedAmount: 180.0),
                new PlanFactSourceAggregate('budget_amount', '2026-01', 1, 10, 100, null, 'USD', 'outflow', 500.0, 500.0),
                new PlanFactSourceAggregate('payment_transaction', '2026-01', 1, 10, 100, null, 'USD', 'outflow', actualAmount: 200.0),
            ],
            dimensions: $this->dimensions(),
            scenario: $this->scenario(),
            budgetVersion: $this->budgetVersion(),
            sourcesCoverage: [],
            warnings: [],
        )->toArray();

        $rubRow = $this->rowByCurrency($result['rows'], 'RUB');
        $usdRow = $this->rowByCurrency($result['rows'], 'USD');

        $this->assertSame(2, $result['summary']['rows_count']);
        $this->assertSame(['RUB', 'USD'], $result['summary']['currencies']);
        $this->assertSame(1000.0, $rubRow['plan_amount']);
        $this->assertSame(1120.0, $rubRow['actual_amount']);
        $this->assertSame(180.0, $rubRow['committed_amount']);
        $this->assertSame(-120.0, $rubRow['variance_amount']);
        $this->assertSame(-12.0, $rubRow['variance_percent']);
        $this->assertSame(PlanFactCalculator::RISK_CRITICAL, $rubRow['risk_level']);
        $this->assertSame(300.0, $usdRow['variance_amount']);
        $this->assertSame(PlanFactCalculator::RISK_LOW, $usdRow['risk_level']);

        $totals = $this->totalsByCurrency($result['totals_by_currency']);
        $this->assertSame(1000.0, $totals['RUB']['plan_amount']);
        $this->assertSame(500.0, $totals['USD']['plan_amount']);
        $this->assertSame(-120.0, $totals['RUB']['variance_amount']);
        $this->assertSame(300.0, $totals['USD']['variance_amount']);

        $drillDownKey = PlanFactDrillDownKey::decode($rubRow['drill_down_key']);
        $this->assertSame('RUB', $drillDownKey->value(PlanFactReportFilters::GROUP_CURRENCY));
        $this->assertSame('2026-01', $drillDownKey->value(PlanFactReportFilters::GROUP_MONTH));
    }

    public function test_income_variance_uses_actual_minus_plan(): void
    {
        $result = (new PlanFactCalculator())->calculate(
            filters: $this->filters([
                PlanFactReportFilters::GROUP_MONTH,
                PlanFactReportFilters::GROUP_BUDGET_ARTICLE,
                PlanFactReportFilters::GROUP_CURRENCY,
            ]),
            aggregates: [
                new PlanFactSourceAggregate('budget_amount', '2026-02', 2, 10, null, null, 'RUB', 'income', 1000.0, 1100.0),
                new PlanFactSourceAggregate('payment_transaction', '2026-02', 2, 10, null, null, 'RUB', 'income', actualAmount: 1250.0),
            ],
            dimensions: $this->dimensions(),
            scenario: $this->scenario(),
            budgetVersion: $this->budgetVersion(),
            sourcesCoverage: [],
            warnings: [],
        )->toArray();

        $row = $result['rows'][0];

        $this->assertSame(250.0, $row['variance_amount']);
        $this->assertSame(25.0, $row['variance_percent']);
        $this->assertSame(PlanFactCalculator::RISK_LOW, $row['risk_level']);
        $this->assertSame('Доходы', $row['budget_article']['name']);
    }

    private function filters(?array $groupBy = null): PlanFactReportFilters
    {
        return new PlanFactReportFilters(
            organizationId: 7,
            periodStart: '2026-01-01',
            periodEnd: '2026-03-31',
            budgetVersionId: 15,
            budgetVersionUuid: 'budget-version-uuid',
            scenarioId: 3,
            scenarioUuid: 'scenario-uuid',
            projectId: null,
            responsibilityCenterId: null,
            responsibilityCenterUuid: null,
            budgetArticleId: null,
            budgetArticleUuid: null,
            counterpartyId: null,
            currency: null,
            groupBy: $groupBy ?? PlanFactReportFilters::DEFAULT_GROUP_BY,
        );
    }

    private function dimensions(): PlanFactDimensions
    {
        return new PlanFactDimensions(
            articles: [
                1 => [
                    'id' => 'article-outflow',
                    'code' => 'OPEX',
                    'name' => 'Расходы',
                    'budget_kind' => 'bdds',
                    'flow_direction' => 'outflow',
                ],
                2 => [
                    'id' => 'article-income',
                    'code' => 'INCOME',
                    'name' => 'Доходы',
                    'budget_kind' => 'bdds',
                    'flow_direction' => 'income',
                ],
            ],
            responsibilityCenters: [
                10 => [
                    'id' => 'center-production',
                    'code' => 'CFO-10',
                    'name' => 'Производство',
                    'center_type' => 'cost',
                ],
            ],
            projects: [
                100 => [
                    'id' => 100,
                    'name' => 'Проект',
                    'status' => 'active',
                ],
            ],
            counterparties: [],
        );
    }

    private function scenario(): array
    {
        return [
            'id' => 'scenario-uuid',
            'code' => 'base',
            'name' => 'Базовый',
            'scenario_type' => 'base',
        ];
    }

    private function budgetVersion(): array
    {
        return [
            'id' => 'budget-version-uuid',
            'name' => 'БДДС 2026',
            'budget_kind' => 'bdds',
            'version_number' => 1,
            'status' => 'active',
            'period' => [
                'id' => 'period-uuid',
                'name' => '2026',
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-12-31',
            ],
        ];
    }

    private function rowByCurrency(array $rows, string $currency): array
    {
        foreach ($rows as $row) {
            if ($row['currency'] === $currency) {
                return $row;
            }
        }

        self::fail("Строка {$currency} не найдена.");
    }

    private function totalsByCurrency(array $totals): array
    {
        $indexed = [];

        foreach ($totals as $total) {
            $indexed[$total['currency']] = $total;
        }

        return $indexed;
    }
}
