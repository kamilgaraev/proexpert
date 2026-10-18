<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportNativeFilters;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class AssistantPublishedReportNativeFiltersTest extends TestCase
{
    public static function sourceFilters(): array
    {
        return [
            'warehouse_risk' => ['inventory_risk', ['period_start' => '2026-10-01', 'period_end' => '2026-10-18', 'statuses' => ['reorder']], true],
            'warehouse_unknown_risk' => ['inventory_risk', ['statuses' => ['unknown']], false],
            'warehouse_empty_risk' => ['inventory_risk', ['statuses' => []], false],
            'invalid_calendar_day' => ['inventory_risk', ['period_start' => '2026-02-30'], false],
            'reversed_period' => ['inventory_risk', ['period_start' => '2026-10-18', 'period_end' => '2026-10-01'], false],
            'labor' => ['project_labor_cost', ['employee_ids' => [1], 'billable' => true, 'statuses' => ['approved'], 'period_to' => '2026-10-18'], true],
            'labor_string_id' => ['project_labor_cost', ['employee_ids' => ['1']], false],
            'labor_string_boolean' => ['project_labor_cost', ['billable' => 'true'], false],
            'labor_draft_status' => ['project_labor_cost', ['statuses' => ['draft']], false],
            'labor_future_period' => ['project_labor_cost', ['period_to' => '2026-10-19'], false],
            'payroll_periods' => ['payroll_readiness', ['payroll_period_ids' => [1], 'issue_codes' => ['missing_rate']], true],
            'required_empty_payroll' => ['payroll_readiness', ['payroll_period_ids' => []], false],
            'capacity_months' => ['workforce_capacity', ['month_from' => '2026-09', 'month_to' => '2026-10'], true],
            'capacity_future_month' => ['workforce_capacity', ['month_from' => '2026-09', 'month_to' => '2026-11'], false],
            'capacity_invalid_month' => ['workforce_capacity', ['month_to' => '2026-13'], false],
            'attendance_unknown_status' => ['attendance_execution', ['statuses' => ['unknown']], false],
            'quality_unknown_severity' => ['quality_defect_flow', ['severity' => 'high'], false],
            'quality_current_week' => ['quality_defect_flow', ['period_from' => '2026-10-12', 'period_to' => '2026-10-25'], true],
            'safety_current_week' => ['safety_incident_actions', ['period_from' => '2026-10-12', 'period_to' => '2026-10-25'], true],
            'quality_future_start' => ['quality_defect_flow', ['period_from' => '2026-10-19', 'period_to' => '2026-10-25'], false],
            'safety_future_start' => ['safety_incident_actions', ['period_from' => '2026-10-19', 'period_to' => '2026-10-25'], false],
            'safety_severity_list' => ['safety_incident_actions', ['severity' => ['minor', 'high']], true],
            'baseline_status' => ['baseline_schedule_variance', ['as_of' => '2026-10-18', 'statuses' => ['in_progress'], 'critical' => true], true],
            'baseline_other_status' => ['baseline_schedule_variance', ['as_of' => '2026-10-18', 'statuses' => ['draft']], false],
            'handover_other_date' => ['handover_readiness', ['as_of' => '2026-10-17'], false],
            'sla_type' => ['customer_sla', ['workflow_types' => ['request']], true],
            'sla_unknown_type' => ['customer_sla', ['workflow_types' => ['act']], false],
            'sla_empty_type' => ['customer_sla', ['workflow_types' => []], false],
            'pnl_scenarios' => ['management_pnl', ['scenarios' => ['actual', 'plan'], 'currencies' => ['RUB']], true],
            'pnl_unknown_scenario' => ['management_pnl', ['scenarios' => ['budget']], false],
            'portfolio_risk' => ['project_portfolio_health', ['risk_levels' => ['high']], true],
            'portfolio_unknown_risk' => ['project_portfolio_health', ['risk_levels' => ['red']], false],
            'lookahead_horizon' => ['lookahead_readiness', ['horizon_days' => 14], true],
            'lookahead_unbounded_horizon' => ['lookahead_readiness', ['horizon_days' => 1000000], false],
            'procurement_native_validation' => ['procurement_cycle', ['period_start' => '2026-10-01', 'period_end' => '2026-10-18', 'currency' => 'RUB', 'award_amount_min' => '1.25'], true],
            'procurement_amount_needs_currency' => ['procurement_cycle', ['award_amount_min' => '1.25'], false],
            'procurement_amount_scale' => ['procurement_cycle', ['currency' => 'RUB', 'award_amount_min' => '1.255'], false],
            'procurement_scope' => ['procurement_cycle', ['project_ids' => [999]], false],
        ];
    }

    #[DataProvider('sourceFilters')]
    public function test_validates_native_values_before_asynchronous_work(string $code, array $input, bool $valid): void
    {
        $store = $this->createMock(BudgetingReportSourceCloseStore::class);
        $store->expects(self::never())->method('find');
        $validator = new AssistantPublishedReportNativeFilters(new BudgetingReportSourceCloseService($store), new ProjectMarginCandidateContract, new BudgetPlanFactCandidateContract);
        $definition = (new ReportDefinitionBuilder)->code($code)->filters(array_map(
            static fn (string $id): array => ['id' => $id, 'required' => $id === 'payroll_period_ids'], array_keys($input),
        ))->payload();
        if (! $valid) {
            $this->expectException(ReportContractException::class);
        }
        $actual = $validator->validate((new ReportExecutionContextBuilder)->build(), $definition, $input, new DateTimeImmutable('2026-10-18T12:00:00Z'));
        ksort($actual);
        ksort($input);
        self::assertSame($input, $actual);
    }

    public static function closeScopes(): array
    {
        return ['current' => [1, true], 'foreign_organization' => [2, false]];
    }

    #[DataProvider('closeScopes')]
    public function test_budgeting_uses_the_native_close_identity_gate(int $closeOrganizationId, bool $valid): void
    {
        $asOf = new DateTimeImmutable('2026-10-18T12:00:00Z');
        $uuid = '00000000-0000-4000-8000-000000000001';
        $input = ['close_id' => '01M530JYMNTDT83G8G5BCHVZ5A', 'organization_id' => 1,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-18', 'scenario_uuid' => $uuid, 'budget_version_uuid' => $uuid,
            'group_by' => ['currency']];
        $close = new BudgetingReportSourceClose($input['close_id'], 'project_margin',
            new BudgetingReportSourceCloseIdentity($closeOrganizationId, $input['period_start'], $input['period_end'], $uuid, $uuid),
            [], ProjectMarginCandidateContract::FORMULA_VERSION, [], str_repeat('a', 64), 1, $asOf->modify('-1 day'), $asOf->modify('+1 day'),
            BudgetingReportSourceCloseStatus::from('approved'), null);
        $store = $this->createMock(BudgetingReportSourceCloseStore::class);
        $store->expects(self::once())->method('find')->with($input['close_id'])->willReturn($close);
        $validator = new AssistantPublishedReportNativeFilters(new BudgetingReportSourceCloseService($store), new ProjectMarginCandidateContract, new BudgetPlanFactCandidateContract);
        $definition = (new ReportDefinitionBuilder)->code('project_margin')->filters((new ProjectMarginCandidateContract)->filters())->payload();
        if (! $valid) {
            $this->expectException(ReportContractException::class);
        }
        self::assertSame($input, $validator->validate((new ReportExecutionContextBuilder)->build(), $definition, $input, $asOf));
    }
}
