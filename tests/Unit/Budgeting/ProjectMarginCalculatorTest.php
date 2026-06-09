<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceAggregate;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectMarginCalculatorTest extends TestCase
{
    public function test_calculates_project_margin_plan_actual_and_variance(): void
    {
        $filters = $this->filters();
        $calculator = new ProjectMarginCalculator();
        $result = $calculator->calculate(
            filters: $filters,
            aggregates: [
                new ProjectMarginSourceAggregate(
                    periodMonth: '2026-01-01',
                    budgetArticleId: 10,
                    responsibilityCenterId: 20,
                    projectId: 30,
                    contractId: 40,
                    counterpartyId: 50,
                    currency: 'RUB',
                    planRevenue: 1000.0,
                    planCost: 600.0,
                    forecastRevenue: 1100.0,
                    forecastCost: 620.0,
                    actualRevenue: 900.0,
                    actualCost: 500.0,
                    sourceTypes: ['budget_amount', 'contract_performance_act', 'payment_document'],
                    problemFlags: [],
                    riskFlags: [],
                    qualityStatus: 'actual',
                    sourceRowsCount: 3,
                ),
            ],
            dimensions: $this->dimensions(),
            scenario: ['id' => 'scenario-1', 'name' => 'Base'],
            budgetVersion: ['id' => 'budget-1', 'name' => 'BDR'],
            sourcesCoverage: [],
            warnings: [],
            meta: ['generated_at' => '2026-01-31T12:00:00+03:00'],
        );

        $row = $result['rows'][0];

        $this->assertSame(400.0, $row['plan']['gross_margin']);
        $this->assertSame(40.0, $row['plan']['margin_percent']);
        $this->assertSame(400.0, $row['actual']['gross_margin']);
        $this->assertSame(44.44, $row['actual']['margin_percent']);
        $this->assertSame(-100.0, $row['variance']['revenue']);
        $this->assertSame(100.0, $row['variance']['cost']);
        $this->assertSame(0.0, $row['variance']['gross_margin']);
        $this->assertSame(4.44, $row['variance']['margin_percent']);
        $this->assertSame('D-40', $row['contract']['number']);
        $this->assertSame(['RUB'], $result['summary']['currencies']);
        $this->assertTrue($result['summary']['has_actuals']);
        $this->assertTrue($result['summary']['has_plan']);
    }

    public function test_drill_down_key_keeps_grouped_contract_and_flags_are_propagated(): void
    {
        $calculator = new ProjectMarginCalculator();
        $result = $calculator->calculate(
            filters: $this->filters(),
            aggregates: [
                new ProjectMarginSourceAggregate(
                    periodMonth: '2026-02-01',
                    budgetArticleId: null,
                    responsibilityCenterId: null,
                    projectId: 30,
                    contractId: 40,
                    counterpartyId: null,
                    currency: 'RUB',
                    planRevenue: 0.0,
                    planCost: 0.0,
                    forecastRevenue: 0.0,
                    forecastCost: 0.0,
                    actualRevenue: 0.0,
                    actualCost: 250.0,
                    sourceTypes: ['warehouse_movement'],
                    problemFlags: ['missing_budget_article'],
                    riskFlags: ['indirect_cost_policy_sensitive'],
                    qualityStatus: 'attention',
                    sourceRowsCount: 1,
                ),
            ],
            dimensions: $this->dimensions(),
            scenario: null,
            budgetVersion: null,
            sourcesCoverage: [],
            warnings: [],
        );

        $row = $result['rows'][0];
        $key = ProjectMarginDrillDownKey::decode($row['drill_down_key']);

        $this->assertSame(40, $key->value(ProjectMarginReportFilters::GROUP_CONTRACT));
        $this->assertSame(['missing_budget_article'], $row['problem_flags']);
        $this->assertSame(['indirect_cost_policy_sensitive'], $row['risk_flags']);
        $this->assertSame('attention', $result['summary']['quality_status']);
    }

    public function test_drill_down_key_rejects_invalid_month_dimension(): void
    {
        $key = ProjectMarginDrillDownKey::encode(
            [ProjectMarginReportFilters::GROUP_MONTH],
            [ProjectMarginReportFilters::GROUP_MONTH => '2026-99'],
        );

        $this->expectException(InvalidArgumentException::class);

        ProjectMarginDrillDownKey::decode($key);
    }

    private function filters(): ProjectMarginReportFilters
    {
        return new ProjectMarginReportFilters(
            organizationId: 1,
            periodStart: '2026-01-01',
            periodEnd: '2026-12-31',
            budgetVersionId: 1,
            budgetVersionUuid: 'budget-1',
            scenarioId: 1,
            scenarioUuid: 'scenario-1',
            projectId: null,
            contractId: null,
            responsibilityCenterId: null,
            responsibilityCenterUuid: null,
            budgetArticleId: null,
            budgetArticleUuid: null,
            counterpartyId: null,
            currency: null,
            groupBy: [
                ProjectMarginReportFilters::GROUP_MONTH,
                ProjectMarginReportFilters::GROUP_PROJECT,
                ProjectMarginReportFilters::GROUP_CONTRACT,
                ProjectMarginReportFilters::GROUP_CURRENCY,
            ],
        );
    }

    private function dimensions(): ProjectMarginDimensions
    {
        return new ProjectMarginDimensions(
            articles: [
                10 => ['id' => 'article-10', 'code' => '90', 'name' => 'Revenue'],
            ],
            responsibilityCenters: [
                20 => ['id' => 'center-20', 'code' => 'CFO', 'name' => 'Projects'],
            ],
            projects: [
                30 => ['id' => 30, 'name' => 'Project'],
            ],
            contracts: [
                40 => ['id' => 40, 'number' => 'D-40', 'subject' => 'Works'],
            ],
            counterparties: [
                50 => ['id' => 50, 'name' => 'Counterparty'],
            ],
        );
    }
}
