<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

final class CfoCommandCenterPayloadBuilder
{
    private const SEVERITY_RANK = [
        'info' => 1,
        'warning' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function build(
        array $filters,
        array $aggregates,
        array $items,
        array $sourceOfTruth,
        array $freshness,
        string $generatedAt,
        int $itemLimit,
    ): array {
        $problemFlags = $this->problemFlags($aggregates);
        $riskFlags = $this->riskFlags($aggregates);
        $actions = $this->actions($aggregates, $problemFlags, $riskFlags);

        return [
            'summary' => $this->summary($filters, $aggregates, $problemFlags, $riskFlags, $actions),
            'aggregates' => $aggregates,
            'items' => $items,
            'problem_flags' => $problemFlags,
            'risk_flags' => $riskFlags,
            'actions' => $actions,
            'filters' => $filters,
            'meta' => [
                'generated_at' => $generatedAt,
                'item_limits' => [
                    'default' => $itemLimit,
                    'upcoming_payments' => $itemLimit,
                    'expected_inflows' => $itemLimit,
                    'overdue' => $itemLimit,
                    'limit_overruns' => $itemLimit,
                    'plan_fact_deviations' => $itemLimit,
                    'approval_blockers' => $itemLimit,
                    'one_c_exchange_issues' => $itemLimit,
                    'top_problem_projects' => $itemLimit,
                ],
                'source_of_truth' => $sourceOfTruth,
                'freshness' => $freshness,
            ],
        ];
    }

    private function summary(
        array $filters,
        array $aggregates,
        array $problemFlags,
        array $riskFlags,
        array $actions,
    ): array {
        $calendar = $aggregates['payment_calendar']['summary'] ?? [];
        $cashGap = $aggregates['cash_gap'] ?? [];
        $limits = $aggregates['limits']['summary'] ?? [];
        $planFact = $aggregates['plan_fact']['summary'] ?? [];
        $approvals = $aggregates['approvals']['summary'] ?? [];
        $oneCExchange = $aggregates['one_c_exchange']['summary'] ?? [];
        $projectPortfolio = $aggregates['project_portfolio']['summary'] ?? [];

        return [
            'health' => $this->overallHealth($problemFlags, $riskFlags),
            'period' => [
                'from' => $filters['period_start'] ?? null,
                'to' => $filters['period_end'] ?? null,
            ],
            'currency' => $filters['currency'] ?? null,
            'cash_position' => [
                'available' => (bool) ($cashGap['available'] ?? false),
                'currencies' => $cashGap['currencies'] ?? [],
                'by_currency' => $cashGap['cash_position_by_currency'] ?? [],
            ],
            'cash_gap' => [
                'available' => (bool) ($cashGap['available'] ?? false),
                'has_gap' => (bool) ($cashGap['has_gap'] ?? false),
                'first_gap_date' => $cashGap['first_gap_date'] ?? null,
                'max_gap_amount' => $cashGap['max_gap_amount'] ?? 0.0,
                'highest_risk_level' => $cashGap['highest_risk_level'] ?? 'low',
            ],
            'payments' => [
                'items_count' => (int) ($calendar['items_count'] ?? 0),
                'upcoming_outflow_amount' => $this->money($calendar['upcoming_outflow_amount'] ?? 0.0),
                'expected_inflow_amount' => $this->money($calendar['expected_inflow_amount'] ?? 0.0),
                'overdue_outflow_amount' => $this->money($calendar['overdue_outflow_amount'] ?? 0.0),
                'overdue_inflow_amount' => $this->money($calendar['overdue_inflow_amount'] ?? 0.0),
                'overdue_count' => (int) ($calendar['overdue_count'] ?? 0),
            ],
            'limits' => [
                'reserved_amount' => $this->money($limits['reserved_amount'] ?? 0.0),
                'reserved_count' => (int) ($limits['reserved_count'] ?? 0),
                'warning_count' => (int) ($limits['warning_count'] ?? 0),
                'exceeded_count' => (int) ($limits['exceeded_count'] ?? 0),
                'requires_exception_count' => (int) ($limits['requires_exception_count'] ?? 0),
                'blocked_count' => (int) ($limits['blocked_count'] ?? 0),
            ],
            'plan_fact' => [
                'available' => (bool) ($aggregates['plan_fact']['available'] ?? false),
                'rows_count' => (int) ($planFact['rows_count'] ?? 0),
                'highest_risk_level' => $planFact['highest_risk_level'] ?? 'low',
                'critical_rows_count' => (int) ($planFact['critical_rows_count'] ?? 0),
                'high_rows_count' => (int) ($planFact['high_rows_count'] ?? 0),
            ],
            'approvals' => [
                'pending_count' => (int) ($approvals['pending_count'] ?? 0),
                'pending_documents_count' => (int) ($approvals['pending_documents_count'] ?? 0),
            ],
            'one_c_exchange' => [
                'available' => (bool) ($aggregates['one_c_exchange']['available'] ?? false),
                'health' => $oneCExchange['health'] ?? 'unknown',
                'problem_count' => (int) ($oneCExchange['problem_count'] ?? 0),
                'open_conflicts_count' => (int) ($oneCExchange['open_conflicts_count'] ?? 0),
            ],
            'project_portfolio' => [
                'available' => (bool) ($aggregates['project_portfolio']['available'] ?? false),
                'projects_count' => (int) ($projectPortfolio['projects_count'] ?? 0),
                'active_projects_count' => (int) ($projectPortfolio['active_projects_count'] ?? 0),
                'problem_projects_count' => (int) ($projectPortfolio['problem_projects_count'] ?? 0),
                'risk_projects_count' => (int) ($projectPortfolio['risk_projects_count'] ?? 0),
                'cash_gap_projects_count' => (int) ($projectPortfolio['cash_gap_projects_count'] ?? 0),
                'budget_deviation_projects_count' => (int) ($projectPortfolio['budget_deviation_projects_count'] ?? 0),
                'top_problem_projects_count' => (int) ($projectPortfolio['top_problem_projects_count'] ?? 0),
                'freshness_status' => $projectPortfolio['freshness_status'] ?? 'unknown',
                'by_currency' => $projectPortfolio['by_currency'] ?? [],
                'problem_flags' => $projectPortfolio['problem_flags'] ?? [],
                'risk_flags' => $projectPortfolio['risk_flags'] ?? [],
            ],
            'problem_flags_count' => count($problemFlags),
            'risk_flags_count' => count($riskFlags),
            'actions_count' => count($actions),
        ];
    }

    private function problemFlags(array $aggregates): array
    {
        $flags = [];
        $cashGap = $aggregates['cash_gap'] ?? [];
        $limits = $aggregates['limits']['summary'] ?? [];
        $planFact = $aggregates['plan_fact'] ?? [];
        $approvals = $aggregates['approvals']['summary'] ?? [];
        $oneCExchange = $aggregates['one_c_exchange']['summary'] ?? [];
        $projectPortfolio = $aggregates['project_portfolio']['summary'] ?? [];

        if (($cashGap['available'] ?? false) === false || ($cashGap['unavailable_currencies'] ?? []) !== []) {
            $flags[] = $this->flag(
                'cash_gap_unavailable',
                'warning',
                'budgeting.cfo_command_center.flags.cash_gap_unavailable',
                'cash_gap',
                ['unavailable_currencies' => $cashGap['unavailable_currencies'] ?? []],
            );
        }

        if ((int) ($limits['blocked_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'budget_limit_blocked',
                'critical',
                'budgeting.cfo_command_center.flags.budget_limit_blocked',
                'limits',
                ['count' => (int) $limits['blocked_count']],
            );
        }

        if ((int) ($limits['requires_exception_count'] ?? 0) > 0 || (int) ($limits['exceeded_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'budget_limit_attention',
                'high',
                'budgeting.cfo_command_center.flags.budget_limit_attention',
                'limits',
                [
                    'requires_exception_count' => (int) ($limits['requires_exception_count'] ?? 0),
                    'exceeded_count' => (int) ($limits['exceeded_count'] ?? 0),
                ],
            );
        }

        if (($aggregates['project_portfolio']['available'] ?? true) === false) {
            $flags[] = $this->flag(
                'project_portfolio_unavailable',
                'warning',
                'budgeting.cfo_command_center.flags.project_portfolio_unavailable',
                'project_portfolio',
            );
        }

        if ((int) ($projectPortfolio['problem_projects_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'project_portfolio_attention',
                'high',
                'budgeting.cfo_command_center.flags.project_portfolio_attention',
                'project_portfolio',
                [
                    'problem_projects_count' => (int) ($projectPortfolio['problem_projects_count'] ?? 0),
                    'problem_flags' => $projectPortfolio['problem_flags'] ?? [],
                ],
            );
        }

        if (($planFact['available'] ?? true) === false) {
            $flags[] = $this->flag(
                'plan_fact_unavailable',
                'warning',
                'budgeting.cfo_command_center.flags.plan_fact_unavailable',
                'plan_fact',
            );
        }

        if ((int) ($approvals['pending_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'payment_approvals_pending',
                'warning',
                'budgeting.cfo_command_center.flags.payment_approvals_pending',
                'approvals',
                ['count' => (int) $approvals['pending_count']],
            );
        }

        if (in_array($oneCExchange['health'] ?? 'ok', ['critical', 'warning'], true)) {
            $flags[] = $this->flag(
                'one_c_exchange_attention',
                ($oneCExchange['health'] ?? null) === 'critical' ? 'critical' : 'warning',
                'budgeting.cfo_command_center.flags.one_c_exchange_attention',
                'one_c_exchange',
                [
                    'problem_count' => (int) ($oneCExchange['problem_count'] ?? 0),
                    'open_conflicts_count' => (int) ($oneCExchange['open_conflicts_count'] ?? 0),
                ],
            );
        }

        return $this->sortFlags($flags);
    }

    private function riskFlags(array $aggregates): array
    {
        $flags = [];
        $cashGap = $aggregates['cash_gap'] ?? [];
        $calendar = $aggregates['payment_calendar']['summary'] ?? [];
        $planFact = $aggregates['plan_fact']['summary'] ?? [];
        $projectPortfolio = $aggregates['project_portfolio']['summary'] ?? [];

        if ((bool) ($cashGap['has_gap'] ?? false)) {
            $flags[] = $this->flag(
                'cash_gap_risk',
                ($cashGap['highest_risk_level'] ?? null) === 'critical' ? 'critical' : 'high',
                'budgeting.cfo_command_center.flags.cash_gap_risk',
                'cash_gap',
                [
                    'first_gap_date' => $cashGap['first_gap_date'] ?? null,
                    'max_gap_amount' => $cashGap['max_gap_amount'] ?? 0.0,
                ],
            );
        }

        if ((int) ($projectPortfolio['cash_gap_projects_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'project_cash_gap_risk',
                'high',
                'budgeting.cfo_command_center.flags.project_cash_gap_risk',
                'project_portfolio',
                [
                    'cash_gap_projects_count' => (int) ($projectPortfolio['cash_gap_projects_count'] ?? 0),
                ],
            );
        }

        if ((int) ($projectPortfolio['risk_projects_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'project_portfolio_risk',
                'high',
                'budgeting.cfo_command_center.flags.project_portfolio_risk',
                'project_portfolio',
                [
                    'risk_projects_count' => (int) ($projectPortfolio['risk_projects_count'] ?? 0),
                    'risk_flags' => $projectPortfolio['risk_flags'] ?? [],
                ],
            );
        }

        if ((int) ($calendar['overdue_count'] ?? 0) > 0) {
            $flags[] = $this->flag(
                'overdue_cash_flows',
                'high',
                'budgeting.cfo_command_center.flags.overdue_cash_flows',
                'payment_calendar',
                [
                    'overdue_count' => (int) $calendar['overdue_count'],
                    'overdue_outflow_amount' => $this->money($calendar['overdue_outflow_amount'] ?? 0.0),
                    'overdue_inflow_amount' => $this->money($calendar['overdue_inflow_amount'] ?? 0.0),
                ],
            );
        }

        if (in_array($planFact['highest_risk_level'] ?? 'low', ['high', 'critical'], true)) {
            $flags[] = $this->flag(
                'plan_fact_deviation',
                ($planFact['highest_risk_level'] ?? null) === 'critical' ? 'critical' : 'high',
                'budgeting.cfo_command_center.flags.plan_fact_deviation',
                'plan_fact',
                [
                    'critical_rows_count' => (int) ($planFact['critical_rows_count'] ?? 0),
                    'high_rows_count' => (int) ($planFact['high_rows_count'] ?? 0),
                ],
            );
        }

        return $this->sortFlags($flags);
    }

    private function actions(array $aggregates, array $problemFlags, array $riskFlags): array
    {
        $actions = [];
        $cashGap = $aggregates['cash_gap'] ?? [];
        $calendar = $aggregates['payment_calendar']['summary'] ?? [];
        $limits = $aggregates['limits']['summary'] ?? [];
        $planFact = $aggregates['plan_fact']['summary'] ?? [];
        $approvals = $aggregates['approvals']['summary'] ?? [];
        $oneCExchange = $aggregates['one_c_exchange']['summary'] ?? [];
        $projectPortfolio = $aggregates['project_portfolio']['summary'] ?? [];

        if ((bool) ($cashGap['has_gap'] ?? false)) {
            $actions[] = $this->action(
                'cover_cash_gap',
                'critical',
                'budgeting.cfo_command_center.actions.cover_cash_gap',
                'cash_gap',
                '/payments/calendar?cash_gap=1',
                $cashGap['first_gap_date'] ?? null,
            );
        }

        if ((int) ($calendar['overdue_count'] ?? 0) > 0) {
            $actions[] = $this->action(
                'review_overdue_cash_flows',
                'high',
                'budgeting.cfo_command_center.actions.review_overdue_cash_flows',
                'payment_calendar',
                '/payments?tab=documents&filter=overdue',
                null,
                (int) $calendar['overdue_count'],
            );
        }

        if ((int) ($approvals['pending_count'] ?? 0) > 0) {
            $actions[] = $this->action(
                'approve_pending_payments',
                'high',
                'budgeting.cfo_command_center.actions.approve_pending_payments',
                'approvals',
                '/payments?tab=approvals',
                null,
                (int) $approvals['pending_count'],
            );
        }

        if (
            (int) ($projectPortfolio['top_problem_projects_count'] ?? 0) > 0
            || (int) ($projectPortfolio['problem_projects_count'] ?? 0) > 0
            || (int) ($projectPortfolio['risk_projects_count'] ?? 0) > 0
        ) {
            $actions[] = $this->action(
                'review_problem_projects',
                'high',
                'budgeting.cfo_command_center.actions.review_problem_projects',
                'project_portfolio',
                '/budgeting/cfo-command-center?focus=problem_projects',
                null,
                max(
                    (int) ($projectPortfolio['top_problem_projects_count'] ?? 0),
                    (int) ($projectPortfolio['problem_projects_count'] ?? 0),
                    (int) ($projectPortfolio['risk_projects_count'] ?? 0),
                ),
            );
        }

        if (
            (int) ($limits['blocked_count'] ?? 0) > 0
            || (int) ($limits['requires_exception_count'] ?? 0) > 0
            || (int) ($limits['exceeded_count'] ?? 0) > 0
        ) {
            $actions[] = $this->action(
                'review_budget_limits',
                (int) ($limits['blocked_count'] ?? 0) > 0 ? 'critical' : 'high',
                'budgeting.cfo_command_center.actions.review_budget_limits',
                'limits',
                '/budgeting/limits',
            );
        }

        if (in_array($planFact['highest_risk_level'] ?? 'low', ['high', 'critical'], true)) {
            $actions[] = $this->action(
                'review_plan_fact_deviations',
                ($planFact['highest_risk_level'] ?? null) === 'critical' ? 'critical' : 'high',
                'budgeting.cfo_command_center.actions.review_plan_fact_deviations',
                'plan_fact',
                '/budgeting/plan-fact',
            );
        }

        if (in_array($oneCExchange['health'] ?? 'ok', ['critical', 'warning'], true)) {
            $actions[] = $this->action(
                'review_one_c_exchange',
                ($oneCExchange['health'] ?? null) === 'critical' ? 'critical' : 'warning',
                'budgeting.cfo_command_center.actions.review_one_c_exchange',
                'one_c_exchange',
                '/one-c-exchange/monitoring',
            );
        }

        usort($actions, fn (array $left, array $right): int => [
            -$this->severityRank((string) $left['priority']),
            (string) $left['code'],
        ] <=> [
            -$this->severityRank((string) $right['priority']),
            (string) $right['code'],
        ]);

        return array_values($actions);
    }

    private function flag(string $code, string $severity, string $messageKey, string $source, array $details = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => trans_message($messageKey),
            'source' => $source,
            'details' => $details,
        ];
    }

    private function action(
        string $code,
        string $priority,
        string $titleKey,
        string $source,
        string $routeHint,
        ?string $dueDate = null,
        ?int $itemsCount = null,
    ): array {
        return [
            'code' => $code,
            'priority' => $priority,
            'title' => trans_message($titleKey),
            'source' => $source,
            'route_hint' => $routeHint,
            'due_date' => $dueDate,
            'items_count' => $itemsCount,
        ];
    }

    private function sortFlags(array $flags): array
    {
        usort($flags, fn (array $left, array $right): int => [
            -$this->severityRank((string) $left['severity']),
            (string) $left['code'],
        ] <=> [
            -$this->severityRank((string) $right['severity']),
            (string) $right['code'],
        ]);

        return array_values($flags);
    }

    private function overallHealth(array $problemFlags, array $riskFlags): string
    {
        $highest = 'info';

        foreach ([...$problemFlags, ...$riskFlags] as $flag) {
            if ($this->severityRank((string) ($flag['severity'] ?? 'info')) > $this->severityRank($highest)) {
                $highest = (string) $flag['severity'];
            }
        }

        return match ($highest) {
            'critical' => 'critical',
            'high', 'warning' => 'warning',
            default => 'ok',
        };
    }

    private function severityRank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? 0;
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }
}
