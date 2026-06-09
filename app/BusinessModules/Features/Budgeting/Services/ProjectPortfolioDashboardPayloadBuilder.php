<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectPortfolioDashboardFilters;

use function trans_message;

final class ProjectPortfolioDashboardPayloadBuilder
{
    private const RISK_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    private const SEVERITY_RANK = [
        'info' => 1,
        'warning' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function build(
        ProjectPortfolioDashboardFilters $filters,
        array $projects,
        array $components,
        string $generatedAt,
    ): array {
        $portfolioRows = $this->projectRows($filters, $projects, $components);
        $rows = array_slice($portfolioRows, 0, $filters->limit);
        $totalsByCurrency = $this->totalsByCurrency($portfolioRows);
        $problemFlags = $this->topLevelFlags('problem_flags', $components, $portfolioRows);
        $riskFlags = $this->topLevelFlags('risk_flags', $components, $portfolioRows);
        $actions = $this->actions($filters, $portfolioRows);
        $freshness = $this->freshness($components, $portfolioRows, $generatedAt);

        return [
            'summary' => $this->summary($filters, $projects, $portfolioRows, count($rows), $totalsByCurrency, $problemFlags, $riskFlags, $actions, $freshness),
            'totals_by_currency' => $totalsByCurrency,
            'projects' => $rows,
            'risk_flags' => $riskFlags,
            'problem_flags' => $problemFlags,
            'filters' => $filters->toArray(),
            'source_of_truth' => $this->sourceOfTruth(),
            'freshness' => $freshness,
            'actions' => $actions,
            'meta' => [
                'generated_at' => $generatedAt,
                'as_of_date' => $filters->asOfDate,
                'limit' => $filters->limit,
                'top_n' => $filters->topN,
                'projects_available' => count($projects),
                'projects_returned' => count($rows),
            ],
        ];
    }

    private function projectRows(ProjectPortfolioDashboardFilters $filters, array $projects, array $components): array
    {
        $rows = [];
        $defaultCurrency = $filters->currency ?? 'RUB';

        foreach ($projects as $project) {
            if (is_array($project) && isset($project['id'])) {
                $this->row($rows, $project, $defaultCurrency);
            }
        }

        $this->applyMarginRows($rows, $projects, $components['project_margin']['report']['rows'] ?? []);
        $this->applyWipRows($rows, $projects, $components['wip_forecast']['report']['rows'] ?? []);
        $this->applyPlanFactRows($rows, $projects, $components['plan_fact']['report']['rows'] ?? []);
        $this->applySimpleRows($rows, $projects, $components['cash_gap']['rows'] ?? [], 'cash_gap');
        $this->applySimpleRows($rows, $projects, $components['limit_risk']['rows'] ?? [], 'limit_risk');
        $this->applySimpleRows($rows, $projects, $components['approvals']['rows'] ?? [], 'approvals');

        foreach ($rows as &$row) {
            $this->applyComponentAvailability($row, $components);
            $this->finalizeRow($row, $filters);
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, fn (array $left, array $right): int => [
            -self::RISK_RANK[(string) $left['risk_level']],
            -(float) $left['risk_score'],
            (string) ($left['project']['name'] ?? ''),
            (string) $left['currency'],
        ] <=> [
            -self::RISK_RANK[(string) $right['risk_level']],
            -(float) $right['risk_score'],
            (string) ($right['project']['name'] ?? ''),
            (string) $right['currency'],
        ]);

        return $rows;
    }

    private function applyMarginRows(array &$rows, array $projects, array $marginRows): void
    {
        foreach ($marginRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? null);
            $target = &$this->row($rows, $projects[$projectId], $currency);
            $actual = is_array($row['actual'] ?? null) ? $row['actual'] : [];
            $forecast = is_array($row['forecast'] ?? null) ? $row['forecast'] : [];

            $target['metrics']['revenue'] = $this->money($actual['revenue'] ?? $target['metrics']['revenue']);
            $target['metrics']['cost'] = $this->money($actual['cost'] ?? $target['metrics']['cost']);
            $target['metrics']['gross_margin'] = $this->money($actual['gross_margin'] ?? $target['metrics']['gross_margin']);
            $target['metrics']['margin_percent'] = $this->nullableFloat($actual['margin_percent'] ?? $target['metrics']['margin_percent']);
            $target['metrics']['forecast_revenue'] = $this->money($forecast['revenue'] ?? $target['metrics']['forecast_revenue']);
            $target['metrics']['forecast_cost'] = $this->money($forecast['cost'] ?? $target['metrics']['forecast_cost']);
            $target['metrics']['forecast_gross_margin'] = $this->money($forecast['gross_margin'] ?? $target['metrics']['forecast_gross_margin']);
            $target['metrics']['forecast_margin_percent'] = $this->nullableFloat($forecast['margin_percent'] ?? $target['metrics']['forecast_margin_percent']);
            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            $target['freshness']['project_margin'] = $row['quality_status'] ?? 'actual';
            unset($target);
        }
    }

    private function applyWipRows(array &$rows, array $projects, array $wipRows): void
    {
        foreach ($wipRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? null);
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $target = &$this->row($rows, $projects[$projectId], $currency);

            foreach (['wip', 'wip_total', 'ftc', 'eac', 'ctc'] as $field) {
                $target['metrics'][$field] = $this->money($metrics[$field] ?? $target['metrics'][$field]);
            }

            $forecastRevenue = $metrics['forecast_revenue'] ?? $metrics['forecast_revenue_at_completion'] ?? null;
            if ($forecastRevenue !== null) {
                $target['metrics']['forecast_revenue'] = $this->money($forecastRevenue);
            }

            if (array_key_exists('forecast_gross_margin', $metrics)) {
                $target['metrics']['forecast_gross_margin'] = $this->money($metrics['forecast_gross_margin']);
            }

            if (array_key_exists('forecast_margin_percent', $metrics)) {
                $target['metrics']['forecast_margin_percent'] = $this->nullableFloat($metrics['forecast_margin_percent']);
            }

            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            $target['freshness']['wip_forecast'] = $row['quality_status'] ?? 'actual';
            unset($target);
        }
    }

    private function applyPlanFactRows(array &$rows, array $projects, array $planFactRows): void
    {
        foreach ($planFactRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? null);
            $target = &$this->row($rows, $projects[$projectId], $currency);
            $target['budget']['plan_amount'] = $this->money($row['plan_amount'] ?? 0.0);
            $target['budget']['forecast_amount'] = $this->money($row['forecast_amount'] ?? 0.0);
            $target['budget']['actual_amount'] = $this->money($row['actual_amount'] ?? 0.0);
            $target['budget']['committed_amount'] = $this->money($row['committed_amount'] ?? 0.0);
            $target['budget']['variance_amount'] = $this->money($row['variance_amount'] ?? 0.0);
            $target['budget']['variance_percent'] = $this->nullableFloat($row['variance_percent'] ?? null);
            $target['budget']['risk_level'] = $this->riskLevel($row['risk_level'] ?? 'low');
            $target['freshness']['plan_fact'] = 'actual';
            unset($target);
        }
    }

    private function applySimpleRows(array &$rows, array $projects, array $simpleRows, string $component): void
    {
        foreach ($simpleRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = isset($row['project_id']) && is_numeric($row['project_id']) ? (int) $row['project_id'] : null;
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? null);
            $target = &$this->row($rows, $projects[$projectId], $currency);

            if ($component === 'cash_gap') {
                $target['cash_gap'] = [
                    'risk_level' => $this->riskLevel($row['risk_level'] ?? 'low'),
                    'has_gap' => (bool) ($row['has_gap'] ?? false),
                    'first_gap_date' => $row['first_gap_date'] ?? null,
                    'max_gap_amount' => $this->money($row['max_gap_amount'] ?? 0.0),
                    'opening_balance' => $this->money($row['opening_balance'] ?? 0.0),
                    'closing_balance' => $this->money($row['closing_balance'] ?? 0.0),
                    'inflows' => $this->money($row['inflows'] ?? 0.0),
                    'outflows' => $this->money($row['outflows'] ?? 0.0),
                ];
                $target['metrics']['cash_gap'] = $target['cash_gap']['max_gap_amount'];
                $target['metrics']['overdue_receivables'] = $this->money($row['overdue_receivables'] ?? 0.0);
                $target['metrics']['overdue_payables'] = $this->money($row['overdue_payables'] ?? 0.0);
                $target['freshness']['cash_gap'] = $row['freshness_status'] ?? 'actual';
            }

            if ($component === 'limit_risk') {
                $target['budget']['limit_risk'] = [
                    'reserved_amount' => $this->money($row['reserved_amount'] ?? 0.0),
                    'reserved_count' => (int) ($row['reserved_count'] ?? 0),
                    'warning_count' => (int) ($row['warning_count'] ?? 0),
                    'exceeded_count' => (int) ($row['exceeded_count'] ?? 0),
                    'requires_exception_count' => (int) ($row['requires_exception_count'] ?? 0),
                    'blocked_count' => (int) ($row['blocked_count'] ?? 0),
                    'latest_checked_at' => $row['latest_checked_at'] ?? null,
                ];
            }

            if ($component === 'approvals') {
                $target['budget']['approval_status'] = [
                    'pending_count' => (int) ($row['pending_count'] ?? 0),
                    'pending_documents_count' => (int) ($row['pending_documents_count'] ?? 0),
                    'latest_pending_created_at' => $row['latest_pending_created_at'] ?? null,
                ];
            }

            unset($target);
        }
    }

    private function &row(array &$rows, array $project, string $currency): array
    {
        $key = ((int) $project['id']) . '|' . $currency;

        if (!isset($rows[$key])) {
            $rows[$key] = [
                'project' => $project,
                'currency' => $currency,
                'metrics' => $this->emptyMetrics(),
                'budget' => $this->emptyBudget(),
                'cash_gap' => $this->emptyCashGap(),
                'risk_level' => 'low',
                'risk_score' => 0.0,
                'risk_flags' => [],
                'problem_flags' => [],
                'freshness' => [
                    'project_margin' => 'actual',
                    'wip_forecast' => 'actual',
                    'plan_fact' => 'actual',
                    'cash_gap' => 'actual',
                    'one_c_exchange' => 'unknown',
                ],
                'drill_down' => [],
            ];
        }

        return $rows[$key];
    }

    private function finalizeRow(array &$row, ProjectPortfolioDashboardFilters $filters): void
    {
        $metrics = &$row['metrics'];
        $budget = &$row['budget'];
        $cashGap = $row['cash_gap'];
        $riskScore = 0.0;
        $riskLevel = 'low';

        if ((float) $metrics['gross_margin'] < 0.0 || (float) $metrics['forecast_gross_margin'] < 0.0) {
            $this->rememberStrings($row['risk_flags'], ['negative_margin']);
            $riskScore += 90.0;
            $riskLevel = $this->highestRisk($riskLevel, 'critical');
        } elseif ($metrics['margin_percent'] !== null && (float) $metrics['margin_percent'] < 10.0) {
            $this->rememberStrings($row['risk_flags'], ['low_margin']);
            $riskScore += 40.0;
            $riskLevel = $this->highestRisk($riskLevel, 'medium');
        }

        if ((bool) $cashGap['has_gap']) {
            $this->rememberStrings($row['risk_flags'], ['cash_gap_risk']);
            $riskScore += min(80.0, (float) $cashGap['max_gap_amount'] / 1000.0);
            $riskLevel = $this->highestRisk($riskLevel, (string) $cashGap['risk_level']);
        }

        if ((float) $metrics['overdue_receivables'] > 0.0) {
            $this->rememberStrings($row['risk_flags'], ['overdue_receivables']);
            $riskScore += min(40.0, (float) $metrics['overdue_receivables'] / 1000.0);
            $riskLevel = $this->highestRisk($riskLevel, 'high');
        }

        if ((float) $metrics['overdue_payables'] > 0.0) {
            $this->rememberStrings($row['risk_flags'], ['overdue_payables']);
            $riskScore += min(30.0, (float) $metrics['overdue_payables'] / 1000.0);
            $riskLevel = $this->highestRisk($riskLevel, 'medium');
        }

        if (in_array($budget['risk_level'], ['high', 'critical'], true)) {
            $this->rememberStrings($row['risk_flags'], ['budget_variance_risk']);
            $riskScore += self::RISK_RANK[(string) $budget['risk_level']] * 15.0;
            $riskLevel = $this->highestRisk($riskLevel, (string) $budget['risk_level']);
        }

        $limitRisk = $budget['limit_risk'];
        if ((int) $limitRisk['blocked_count'] > 0 || (int) $limitRisk['requires_exception_count'] > 0 || (int) $limitRisk['exceeded_count'] > 0) {
            $this->rememberStrings($row['problem_flags'], ['budget_limit_risk']);
            $riskScore += ((int) $limitRisk['blocked_count'] * 35.0) + ((int) $limitRisk['requires_exception_count'] * 20.0) + ((int) $limitRisk['exceeded_count'] * 15.0);
            $riskLevel = $this->highestRisk($riskLevel, (int) $limitRisk['blocked_count'] > 0 ? 'critical' : 'high');
        }

        $approvalStatus = $budget['approval_status'];
        if ((int) $approvalStatus['pending_count'] > 0) {
            $this->rememberStrings($row['problem_flags'], ['approvals_pending']);
            $riskScore += min(40.0, (int) $approvalStatus['pending_count'] * 5.0);
            $riskLevel = $this->highestRisk($riskLevel, 'medium');
        }

        foreach ($row['problem_flags'] as $flag => $_) {
            if (str_ends_with((string) $flag, '_unavailable') || str_ends_with((string) $flag, '_partial')) {
                $riskScore += 8.0;
                $riskLevel = $this->highestRisk($riskLevel, 'medium');
            }
        }

        $row['risk_level'] = $riskLevel;
        $row['risk_score'] = $this->money($riskScore);
        $row['risk_flags'] = array_values(array_keys($row['risk_flags']));
        $row['problem_flags'] = array_values(array_keys($row['problem_flags']));
        $row['drill_down'] = $this->drillDown($filters, (int) $row['project']['id'], (string) $row['currency']);
        unset($metrics, $budget);
    }

    private function applyComponentAvailability(array &$row, array $components): void
    {
        foreach ([
            'project_margin' => 'project_margin_unavailable',
            'plan_fact' => 'plan_fact_unavailable',
            'wip_forecast' => 'wip_forecast_unavailable',
            'cash_gap' => 'cash_gap_unavailable',
        ] as $component => $flag) {
            if (($components[$component]['available'] ?? true) === false) {
                $this->rememberStrings($row['problem_flags'], [$flag]);
                $row['freshness'][$component] = 'unavailable';
            }
        }

        if (($components['wip_forecast']['partial_reason'] ?? null) === 'responsibility_center_filter_not_supported') {
            $this->rememberStrings($row['problem_flags'], ['wip_forecast_responsibility_center_partial']);
            $row['freshness']['wip_forecast'] = 'partial';
        }

        $oneCStatus = $components['one_c_exchange']['freshness']['status'] ?? null;
        if (is_string($oneCStatus)) {
            $row['freshness']['one_c_exchange'] = $oneCStatus;
            if (in_array($oneCStatus, ['warning', 'critical', 'stale'], true)) {
                $this->rememberStrings($row['problem_flags'], ['external_confirmation_attention']);
            }
        }
    }

    private function totalsByCurrency(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] ??= [
                'currency' => $currency,
                'revenue' => 0.0,
                'cost' => 0.0,
                'gross_margin' => 0.0,
                'forecast_revenue' => 0.0,
                'forecast_cost' => 0.0,
                'forecast_gross_margin' => 0.0,
                'wip' => 0.0,
                'wip_total' => 0.0,
                'ftc' => 0.0,
                'eac' => 0.0,
                'ctc' => 0.0,
                'cash_gap' => 0.0,
                'overdue_receivables' => 0.0,
                'overdue_payables' => 0.0,
                'budget_plan' => 0.0,
                'budget_actual' => 0.0,
                'budget_committed' => 0.0,
                'budget_variance' => 0.0,
                'projects_count' => 0,
                'highest_risk_level' => 'low',
            ];

            foreach ([
                'revenue',
                'cost',
                'gross_margin',
                'forecast_revenue',
                'forecast_cost',
                'forecast_gross_margin',
                'wip',
                'wip_total',
                'ftc',
                'eac',
                'ctc',
                'cash_gap',
                'overdue_receivables',
                'overdue_payables',
            ] as $field) {
                $totals[$currency][$field] = $this->money((float) $totals[$currency][$field] + (float) ($row['metrics'][$field] ?? 0.0));
            }

            $totals[$currency]['budget_plan'] = $this->money((float) $totals[$currency]['budget_plan'] + (float) ($row['budget']['plan_amount'] ?? 0.0));
            $totals[$currency]['budget_actual'] = $this->money((float) $totals[$currency]['budget_actual'] + (float) ($row['budget']['actual_amount'] ?? 0.0));
            $totals[$currency]['budget_committed'] = $this->money((float) $totals[$currency]['budget_committed'] + (float) ($row['budget']['committed_amount'] ?? 0.0));
            $totals[$currency]['budget_variance'] = $this->money((float) $totals[$currency]['budget_variance'] + (float) ($row['budget']['variance_amount'] ?? 0.0));
            $totals[$currency]['projects_count']++;
            $totals[$currency]['highest_risk_level'] = $this->highestRisk((string) $totals[$currency]['highest_risk_level'], (string) $row['risk_level']);
        }

        ksort($totals);

        return array_map(function (array $total): array {
            $total['margin_percent'] = $this->percent((float) $total['gross_margin'], (float) $total['revenue']);
            $total['forecast_margin_percent'] = $this->percent((float) $total['forecast_gross_margin'], (float) $total['forecast_revenue']);
            $total['budget_variance_percent'] = $this->percent((float) $total['budget_variance'], (float) $total['budget_plan']);

            return $total;
        }, array_values($totals));
    }

    private function summary(
        ProjectPortfolioDashboardFilters $filters,
        array $projects,
        array $rows,
        int $returnedRowsCount,
        array $totalsByCurrency,
        array $problemFlags,
        array $riskFlags,
        array $actions,
        array $freshness,
    ): array {
        $riskBreakdown = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($rows as $row) {
            $riskBreakdown[(string) $row['risk_level']] = ($riskBreakdown[(string) $row['risk_level']] ?? 0) + 1;
        }

        return [
            'health' => $this->overallHealth($problemFlags, $riskFlags),
            'period' => $filters->period(),
            'projects_available' => count($projects),
            'projects_returned' => $returnedRowsCount,
            'currencies' => array_map(static fn (array $total): string => (string) $total['currency'], $totalsByCurrency),
            'risk_breakdown' => $riskBreakdown,
            'data_status' => $freshness['status'] ?? 'actual',
            'problem_flags_count' => count($problemFlags),
            'risk_flags_count' => count($riskFlags),
            'actions_count' => count($actions),
        ];
    }

    private function topLevelFlags(string $type, array $components, array $rows): array
    {
        $flags = [];

        foreach ($components as $component => $payload) {
            if (($payload['available'] ?? true) === false) {
                $code = $component . '_unavailable';
                $flags[$code] ??= $this->flag($code, 'warning', $component, []);
            }
        }

        foreach ($rows as $row) {
            foreach (($row[$type] ?? []) as $code) {
                $flags[$code] ??= $this->flag((string) $code, $this->flagSeverity((string) $code), $this->flagSource((string) $code), []);
                $flags[$code]['details']['project_ids'] ??= [];
                $flags[$code]['details']['project_ids'][] = (int) $row['project']['id'];
            }
        }

        foreach ($flags as &$flag) {
            if (isset($flag['details']['project_ids'])) {
                $flag['details']['project_ids'] = array_values(array_unique($flag['details']['project_ids']));
                $flag['details']['projects_count'] = count($flag['details']['project_ids']);
            }
        }
        unset($flag);

        return $this->sortFlags(array_values($flags));
    }

    private function actions(ProjectPortfolioDashboardFilters $filters, array $rows): array
    {
        $actions = [];

        foreach (array_slice($rows, 0, $filters->topN) as $row) {
            $projectId = (int) $row['project']['id'];
            $projectName = (string) $row['project']['name'];

            if (in_array('cash_gap_risk', $row['risk_flags'], true)) {
                $actions[] = $this->action('cover_project_cash_gap', 'critical', 'cash_gap', $projectId, $projectName, $row['drill_down']['cash_gap']);
            }

            if (array_intersect(['negative_margin', 'low_margin'], $row['risk_flags']) !== []) {
                $actions[] = $this->action('review_project_margin', (string) $row['risk_level'], 'project_margin', $projectId, $projectName, $row['drill_down']['margin']);
            }

            if (in_array('budget_variance_risk', $row['risk_flags'], true)) {
                $actions[] = $this->action('review_project_budget_variance', (string) $row['budget']['risk_level'], 'plan_fact', $projectId, $projectName, $row['drill_down']['plan_fact']);
            }

            if (in_array('budget_limit_risk', $row['problem_flags'], true)) {
                $actions[] = $this->action('review_project_limits', 'high', 'limits', $projectId, $projectName, $row['drill_down']['cash_gap']);
            }

            if (in_array('wip_forecast_unavailable', $row['problem_flags'], true)) {
                $actions[] = $this->action('update_project_forecast', 'warning', 'wip_forecast', $projectId, $projectName, $row['drill_down']['wip_forecast']);
            }
        }

        usort($actions, fn (array $left, array $right): int => [
            -self::SEVERITY_RANK[(string) $left['priority']],
            (string) $left['code'],
            (int) $left['project_id'],
        ] <=> [
            -self::SEVERITY_RANK[(string) $right['priority']],
            (string) $right['code'],
            (int) $right['project_id'],
        ]);

        return array_slice($actions, 0, $filters->topN);
    }

    private function freshness(array $components, array $rows, string $generatedAt): array
    {
        $sections = [];
        $status = 'actual';

        foreach (['project_margin', 'plan_fact', 'wip_forecast', 'cash_gap', 'limit_risk', 'approvals', 'one_c_exchange'] as $component) {
            $componentFreshness = is_array($components[$component]['freshness'] ?? null)
                ? $components[$component]['freshness']
                : ['status' => ($components[$component]['available'] ?? true) ? 'actual' : 'unavailable'];
            $sections[$component] = $componentFreshness;
            $status = $this->worstFreshness($status, (string) ($componentFreshness['status'] ?? 'actual'));
        }

        foreach ($rows as $row) {
            foreach (($row['freshness'] ?? []) as $rowFreshness) {
                $status = $this->worstFreshness($status, (string) $rowFreshness);
            }
        }

        return [
            'status' => $status,
            'generated_at' => $generatedAt,
            'sections' => $sections,
        ];
    }

    private function sourceOfTruth(): array
    {
        return [
            'portfolio' => [
                'primary' => 'prohelper_epm_management_aggregates',
            ],
            'project_margin' => [
                'primary' => 'prohelper_project_margin_report',
                'confirmation' => ['bank', 'edo', '1c'],
            ],
            'plan_fact' => [
                'primary' => 'prohelper_budget_plan_fact_report',
                'confirmation' => ['bank', '1c'],
            ],
            'wip_forecast' => [
                'primary' => 'prohelper_wip_forecast',
                'confirmation' => ['acts', '1c'],
            ],
            'cash_gap' => [
                'primary' => 'prohelper_payment_calendar_and_cash_gap_forecast',
                'confirmation' => ['bank'],
            ],
            'external_systems' => [
                '1c' => 'confirmation_only',
                'bank' => 'confirmation_only',
                'edo' => 'confirmation_only',
            ],
            'excluded' => [
                'accounting_entries',
                'tax_accounting',
                'regulated_reporting',
                'payroll',
            ],
        ];
    }

    private function drillDown(ProjectPortfolioDashboardFilters $filters, int $projectId, string $currency): array
    {
        $query = [
            'project_id' => $projectId,
            'period_start' => $filters->periodStart,
            'period_end' => $filters->periodEnd,
            'currency' => $currency,
        ];
        $wipQuery = array_merge($query, ['as_of_date' => $filters->asOfDate]);

        return [
            'margin' => $this->drill('/budgeting/project-margin', '/api/v1/admin/budgeting/project-margin', 'budgeting.project_margin', $query),
            'plan_fact' => $this->drill('/budgeting/plan-fact', '/api/v1/admin/budgeting/plan-fact', 'budgeting.plan_fact', $query),
            'wip_forecast' => $this->drill('/budgeting/wip-forecast', '/api/v1/admin/budgeting/wip-forecast', 'budgeting.wip_forecast', $wipQuery),
            'cash_gap' => $this->drill('/budgeting/cfo-command-center', '/api/v1/admin/budgeting/cfo-command-center', 'budgeting.cfo_command_center', $query),
        ];
    }

    private function drill(string $href, string $apiHref, string $routeHint, array $query): array
    {
        $queryString = http_build_query($query);

        return [
            'href' => $href . '?' . $queryString,
            'api_href' => $apiHref . '?' . $queryString,
            'route_hint' => $routeHint,
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'revenue' => 0.0,
            'cost' => 0.0,
            'gross_margin' => 0.0,
            'margin_percent' => null,
            'forecast_revenue' => 0.0,
            'forecast_cost' => 0.0,
            'forecast_gross_margin' => 0.0,
            'forecast_margin_percent' => null,
            'wip' => 0.0,
            'wip_total' => 0.0,
            'ftc' => 0.0,
            'eac' => 0.0,
            'ctc' => 0.0,
            'cash_gap' => 0.0,
            'overdue_receivables' => 0.0,
            'overdue_payables' => 0.0,
        ];
    }

    private function emptyBudget(): array
    {
        return [
            'plan_amount' => 0.0,
            'forecast_amount' => 0.0,
            'actual_amount' => 0.0,
            'committed_amount' => 0.0,
            'variance_amount' => 0.0,
            'variance_percent' => null,
            'risk_level' => 'low',
            'limit_risk' => [
                'reserved_amount' => 0.0,
                'reserved_count' => 0,
                'warning_count' => 0,
                'exceeded_count' => 0,
                'requires_exception_count' => 0,
                'blocked_count' => 0,
                'latest_checked_at' => null,
            ],
            'approval_status' => [
                'pending_count' => 0,
                'pending_documents_count' => 0,
                'latest_pending_created_at' => null,
            ],
        ];
    }

    private function emptyCashGap(): array
    {
        return [
            'risk_level' => 'low',
            'has_gap' => false,
            'first_gap_date' => null,
            'max_gap_amount' => 0.0,
            'opening_balance' => 0.0,
            'closing_balance' => 0.0,
            'inflows' => 0.0,
            'outflows' => 0.0,
        ];
    }

    private function projectId(array $row): ?int
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : null;
        $id = $project['id'] ?? $row['project_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function flag(string $code, string $severity, string $source, array $details): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => trans_message('budgeting.project_portfolio_dashboard.flags.' . $code),
            'source' => $source,
            'details' => $details,
        ];
    }

    private function action(string $code, string $priority, string $source, int $projectId, string $projectName, array $drillDown): array
    {
        return [
            'code' => $code,
            'priority' => $this->actionPriority($priority),
            'title' => trans_message('budgeting.project_portfolio_dashboard.actions.' . $code),
            'source' => $source,
            'project_id' => $projectId,
            'project_name' => $projectName,
            'href' => (string) ($drillDown['href'] ?? ''),
            'api_href' => (string) ($drillDown['api_href'] ?? ''),
            'route_hint' => (string) ($drillDown['route_hint'] ?? ''),
        ];
    }

    private function flagSeverity(string $code): string
    {
        return match ($code) {
            'negative_margin',
            'cash_gap_risk',
            'budget_variance_risk',
            'budget_limit_risk' => 'high',
            'project_margin_unavailable',
            'plan_fact_unavailable',
            'wip_forecast_unavailable',
            'cash_gap_unavailable',
            'external_confirmation_attention',
            'approvals_pending',
            'overdue_receivables' => 'warning',
            default => 'info',
        };
    }

    private function flagSource(string $code): string
    {
        return match ($code) {
            'negative_margin',
            'low_margin',
            'project_margin_unavailable' => 'project_margin',
            'plan_fact_unavailable',
            'budget_variance_risk' => 'plan_fact',
            'wip_forecast_unavailable',
            'wip_forecast_responsibility_center_partial' => 'wip_forecast',
            'cash_gap_unavailable',
            'cash_gap_risk',
            'overdue_receivables',
            'overdue_payables' => 'cash_gap',
            'budget_limit_risk' => 'limits',
            'approvals_pending' => 'approvals',
            'external_confirmation_attention',
            'one_c_exchange_unavailable' => 'one_c_exchange',
            default => 'portfolio',
        };
    }

    private function sortFlags(array $flags): array
    {
        usort($flags, fn (array $left, array $right): int => [
            -self::SEVERITY_RANK[(string) $left['severity']],
            (string) $left['code'],
        ] <=> [
            -self::SEVERITY_RANK[(string) $right['severity']],
            (string) $right['code'],
        ]);

        return array_values($flags);
    }

    private function overallHealth(array $problemFlags, array $riskFlags): string
    {
        $severity = 'info';
        foreach ([...$problemFlags, ...$riskFlags] as $flag) {
            if (self::SEVERITY_RANK[(string) ($flag['severity'] ?? 'info')] > self::SEVERITY_RANK[$severity]) {
                $severity = (string) $flag['severity'];
            }
        }

        return match ($severity) {
            'critical' => 'critical',
            'high', 'warning' => 'warning',
            default => 'ok',
        };
    }

    private function highestRisk(string $left, string $right): string
    {
        return (self::RISK_RANK[$right] ?? 1) > (self::RISK_RANK[$left] ?? 1) ? $right : $left;
    }

    private function riskLevel(mixed $value): string
    {
        $value = is_string($value) ? $value : 'low';

        return array_key_exists($value, self::RISK_RANK) ? $value : 'low';
    }

    private function actionPriority(string $riskLevel): string
    {
        return match ($riskLevel) {
            'critical' => 'critical',
            'high' => 'high',
            'medium', 'warning' => 'warning',
            default => 'info',
        };
    }

    private function worstFreshness(string $left, string $right): string
    {
        $rank = [
            'actual' => 0,
            'ok' => 0,
            'unknown' => 1,
            'warning' => 2,
            'attention' => 2,
            'stale' => 3,
            'partial' => 4,
            'critical' => 5,
            'unavailable' => 6,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private function currency(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? mb_strtoupper(trim($value)) : 'RUB';
    }

    private function rememberStrings(array &$target, mixed $values): void
    {
        if (!is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $target[$value] = true;
            }
        }
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function percent(float $amount, float $base): ?float
    {
        if (abs($base) < 0.01) {
            return null;
        }

        return $this->money(($amount / $base) * 100);
    }
}
