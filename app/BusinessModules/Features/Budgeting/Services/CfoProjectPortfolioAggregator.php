<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;

final class CfoProjectPortfolioAggregator
{
    private const RISK_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function build(
        CfoCommandCenterFilters $filters,
        array $projects,
        array $marginReport,
        array $wipReport,
        array $planFactItems,
        array $calendarItems,
        string $generatedAt,
        int $itemLimit,
    ): array {
        $rows = $this->rows($filters, $projects, $marginReport, $wipReport, $planFactItems, $calendarItems);
        $summary = $this->summary($projects, $rows, $marginReport, $wipReport);

        return [
            'available' => true,
            'summary' => $summary,
            'items' => array_slice($this->problemRows($rows), 0, $itemLimit),
            'meta' => [
                'generated_at' => $generatedAt,
                'item_limit' => $itemLimit,
                'source_reports' => [
                    'project_margin' => '/api/v1/admin/budgeting/project-margin',
                    'plan_fact' => '/api/v1/admin/budgeting/plan-fact',
                    'wip_forecast' => '/api/v1/admin/budgeting/wip-forecast',
                    'cash_gap' => '/api/v1/admin/budgeting/cfo-command-center',
                ],
            ],
        ];
    }

    private function rows(
        CfoCommandCenterFilters $filters,
        array $projects,
        array $marginReport,
        array $wipReport,
        array $planFactItems,
        array $calendarItems,
    ): array {
        $rows = [];

        foreach ($projects as $project) {
            if (!is_array($project) || !isset($project['id'])) {
                continue;
            }

            $currency = $this->currency($filters->currency);
            $rows[$this->key((int) $project['id'], $currency)] = $this->emptyRow($project, $currency, $filters);
        }

        foreach (($marginReport['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);
            $actual = is_array($row['actual'] ?? null) ? $row['actual'] : [];
            $forecast = is_array($row['forecast'] ?? null) ? $row['forecast'] : [];
            $target['metrics']['revenue'] = $this->money($actual['revenue'] ?? 0.0);
            $target['metrics']['cost'] = $this->money($actual['cost'] ?? 0.0);
            $target['metrics']['gross_margin'] = $this->money($actual['gross_margin'] ?? 0.0);
            $target['metrics']['forecast_revenue'] = $this->money($forecast['revenue'] ?? 0.0);
            $target['metrics']['forecast_cost'] = $this->money($forecast['cost'] ?? 0.0);
            $target['metrics']['forecast_gross_margin'] = $this->money($forecast['gross_margin'] ?? 0.0);
            $target['drill_down']['project_margin_key'] = $row['drill_down_key'] ?? null;
            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            unset($target);
        }

        foreach (($wipReport['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);

            foreach (['wip', 'wip_total', 'ftc', 'eac', 'ctc', 'forecast_gross_margin'] as $field) {
                $target['metrics'][$field] = $this->money($metrics[$field] ?? 0.0);
            }

            $target['metrics']['forecast_revenue'] = $this->money(
                $metrics['forecast_revenue'] ?? $metrics['forecast_revenue_at_completion'] ?? $target['metrics']['forecast_revenue'],
            );
            $target['drill_down']['wip_forecast_key'] = $row['drill_down_key'] ?? null;
            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            unset($target);
        }

        foreach ($planFactItems as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || !isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);
            $target['budget_deviation'] = [
                'variance_amount' => $this->money($row['variance_amount'] ?? 0.0),
                'risk_level' => $this->riskLevel($row['risk_level'] ?? 'low'),
                'drill_down_key' => $row['drill_down_key'] ?? null,
            ];

            if (in_array($target['budget_deviation']['risk_level'], ['high', 'critical'], true)) {
                $this->rememberStrings($target['problem_flags'], ['budget_deviation']);
            }

            unset($target);
        }

        foreach ($calendarItems as $item) {
            if (!$item instanceof PaymentCalendarItem || $item->projectId === null || !isset($projects[$item->projectId])) {
                continue;
            }

            $currency = $this->currency($item->currency);
            $target = &$this->row($rows, $projects[$item->projectId], $currency, $filters);
            $amount = $this->money($item->remainingAmount);

            if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
                $target['cash_gap']['inflows'] = $this->money($target['cash_gap']['inflows'] + $amount);
            } elseif ($item->direction === PaymentCalendarItem::DIRECTION_OUTFLOW) {
                $target['cash_gap']['outflows'] = $this->money($target['cash_gap']['outflows'] + $amount);
            }

            unset($target);
        }

        foreach ($rows as &$row) {
            $this->finalizeRow($row);
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, fn (array $left, array $right): int => [
            -self::RISK_RANK[(string) $left['risk_level']],
            -(float) $left['score'],
            (string) ($left['project']['name'] ?? ''),
        ] <=> [
            -self::RISK_RANK[(string) $right['risk_level']],
            -(float) $right['score'],
            (string) ($right['project']['name'] ?? ''),
        ]);

        return $rows;
    }

    private function problemRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['problem_flags'] ?? []) !== [] || ($row['risk_flags'] ?? []) !== [],
        ));
    }

    private function summary(array $projects, array $rows, array $marginReport, array $wipReport): array
    {
        $byCurrency = [];
        $problemFlags = [];
        $riskFlags = [];
        $cashGapProjectIds = [];
        $budgetDeviationProjectIds = [];
        $problemProjectIds = [];
        $riskProjectIds = [];

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $projectId = (int) ($row['project']['id'] ?? 0);
            $byCurrency[$currency] ??= [
                'revenue' => 0.0,
                'cost' => 0.0,
                'gross_margin' => 0.0,
                'forecast_revenue' => 0.0,
                'forecast_cost' => 0.0,
                'forecast_gross_margin' => 0.0,
                'wip_total' => 0.0,
                'ftc' => 0.0,
                'eac' => 0.0,
                'ctc' => 0.0,
                'cash_gap_signal' => 0.0,
            ];

            foreach (['revenue', 'cost', 'gross_margin', 'forecast_revenue', 'forecast_cost', 'forecast_gross_margin', 'wip_total', 'ftc', 'eac', 'ctc'] as $field) {
                $byCurrency[$currency][$field] = $this->money($byCurrency[$currency][$field] + (float) ($row['metrics'][$field] ?? 0.0));
            }

            $byCurrency[$currency]['cash_gap_signal'] = $this->money(
                $byCurrency[$currency]['cash_gap_signal'] + (float) ($row['cash_gap']['signal'] ?? 0.0),
            );

            foreach ($row['problem_flags'] as $flag) {
                $problemFlags[$flag] = true;
            }

            foreach ($row['risk_flags'] as $flag) {
                $riskFlags[$flag] = true;
            }

            if (($row['cash_gap']['has_gap'] ?? false) === true) {
                $cashGapProjectIds[$projectId] = true;
            }

            if (in_array('budget_deviation', $row['problem_flags'], true)) {
                $budgetDeviationProjectIds[$projectId] = true;
            }

            if ($row['problem_flags'] !== [] || $row['risk_flags'] !== []) {
                $problemProjectIds[$projectId] = true;
            }

            if ($row['risk_flags'] !== []) {
                $riskProjectIds[$projectId] = true;
            }
        }

        ksort($byCurrency);

        return [
            'projects_count' => count($projects),
            'active_projects_count' => count(array_filter($projects, static fn (array $project): bool => ($project['status'] ?? null) === 'active')),
            'problem_projects_count' => count($problemProjectIds),
            'risk_projects_count' => count($riskProjectIds),
            'cash_gap_projects_count' => count($cashGapProjectIds),
            'budget_deviation_projects_count' => count($budgetDeviationProjectIds),
            'top_problem_projects_count' => count($problemProjectIds),
            'freshness_status' => $this->freshnessStatus($marginReport, $wipReport),
            'by_currency' => $byCurrency,
            'problem_flags' => array_values(array_keys($problemFlags)),
            'risk_flags' => array_values(array_keys($riskFlags)),
        ];
    }

    private function emptyRow(array $project, string $currency, CfoCommandCenterFilters $filters): array
    {
        return [
            'project' => [
                'id' => (int) $project['id'],
                'name' => (string) ($project['name'] ?? ''),
                'status' => $project['status'] ?? null,
                'project_type' => $project['project_type'] ?? null,
                'project_manager' => $project['project_manager'] ?? null,
            ],
            'currency' => $currency,
            'score' => 0.0,
            'risk_level' => 'low',
            'metrics' => [
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
                'cash_gap_signal' => 0.0,
            ],
            'budget_deviation' => [
                'variance_amount' => 0.0,
                'risk_level' => 'low',
                'drill_down_key' => null,
            ],
            'cash_gap' => [
                'inflows' => 0.0,
                'outflows' => 0.0,
                'signal' => 0.0,
                'has_gap' => false,
            ],
            'problem_flags' => [],
            'risk_flags' => [],
            'drill_down' => [
                'href' => '/budgeting/project-margin?project_id=' . (int) $project['id'],
                'api_href' => '/api/v1/admin/budgeting/project-margin?project_id=' . (int) $project['id'],
                'project_margin_key' => null,
                'wip_forecast_key' => null,
                'period' => $filters->period(),
            ],
        ];
    }

    private function &row(array &$rows, array $project, string $currency, CfoCommandCenterFilters $filters): array
    {
        $key = $this->key((int) $project['id'], $currency);
        $rows[$key] ??= $this->emptyRow($project, $currency, $filters);

        return $rows[$key];
    }

    private function finalizeRow(array &$row): void
    {
        $signal = $this->money((float) $row['cash_gap']['inflows'] - (float) $row['cash_gap']['outflows']);
        $row['cash_gap']['signal'] = $signal;
        $row['cash_gap']['has_gap'] = $signal < 0.0;
        $row['metrics']['cash_gap_signal'] = $signal;

        if ($row['cash_gap']['has_gap']) {
            $this->rememberStrings($row['risk_flags'], ['cash_gap_risk']);
        }

        if ((float) $row['metrics']['gross_margin'] < 0.0 || (float) $row['metrics']['forecast_gross_margin'] < 0.0) {
            $this->rememberStrings($row['risk_flags'], ['negative_margin']);
        }

        $score = 0.0;
        if ($row['cash_gap']['has_gap']) {
            $score += min(100.0, abs((float) $row['cash_gap']['signal']) / 1000.0);
        }

        if (in_array('budget_deviation', $row['problem_flags'], true)) {
            $score += self::RISK_RANK[(string) $row['budget_deviation']['risk_level']] * 10.0;
        }

        if (in_array('negative_margin', $row['risk_flags'], true)) {
            $score += 90.0;
        }

        $riskLevel = 'low';
        if ($row['cash_gap']['has_gap']) {
            $riskLevel = $this->highestRisk($riskLevel, 'high');
        }

        $riskLevel = $this->highestRisk($riskLevel, (string) $row['budget_deviation']['risk_level']);
        if (in_array('negative_margin', $row['risk_flags'], true)) {
            $riskLevel = $this->highestRisk($riskLevel, 'critical');
        }

        $row['score'] = $this->money($score);
        $row['risk_level'] = $riskLevel;
        $row['problem_flags'] = array_values(array_unique($row['problem_flags']));
        $row['risk_flags'] = array_values(array_unique($row['risk_flags']));
    }

    private function projectId(array $row): ?int
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : [];
        $id = $project['id'] ?? $row['project_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function key(int $projectId, string $currency): string
    {
        return $projectId . '|' . $currency;
    }

    private function riskLevel(mixed $value): string
    {
        $value = is_string($value) ? $value : 'low';

        return array_key_exists($value, self::RISK_RANK) ? $value : 'low';
    }

    private function highestRisk(string $left, string $right): string
    {
        return (self::RISK_RANK[$right] ?? 1) > (self::RISK_RANK[$left] ?? 1) ? $right : $left;
    }

    private function rememberStrings(array &$target, mixed $values): void
    {
        if (!is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $target[] = $value;
            }
        }
    }

    private function freshnessStatus(array $marginReport, array $wipReport): string
    {
        $statuses = [
            $marginReport['summary']['quality_status'] ?? null,
            $wipReport['summary']['quality_status'] ?? null,
            $wipReport['freshness']['status'] ?? null,
        ];
        $rank = ['actual' => 1, 'attention' => 2, 'partial' => 3, 'stale' => 4, 'unavailable' => 5];
        $status = 'actual';

        foreach ($statuses as $candidate) {
            if (is_string($candidate) && ($rank[$candidate] ?? 0) > ($rank[$status] ?? 0)) {
                $status = $candidate;
            }
        }

        return $status;
    }

    private function currency(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? mb_strtoupper(trim($value)) : 'RUB';
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }
}
