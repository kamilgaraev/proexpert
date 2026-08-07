<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;

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
        return $this->buildResult(
            $filters,
            $projects,
            $marginReport,
            $wipReport,
            $planFactItems,
            $calendarItems,
            $generatedAt,
            $itemLimit,
        )->toArray();
    }

    public function buildResult(
        CfoCommandCenterFilters $filters,
        array $projects,
        array $marginReport,
        array $wipReport,
        array $planFactItems,
        array $calendarItems,
        string $generatedAt,
        int $itemLimit,
        bool $seedProjects = true,
    ): ProjectPortfolioProjectionResult {
        $rows = $this->rows(
            $filters,
            $projects,
            $marginReport,
            $wipReport,
            $planFactItems,
            $calendarItems,
            $seedProjects,
        );
        $summary = $this->summary($projects, $rows, $marginReport, $wipReport);

        return ProjectPortfolioProjectionResult::fromAggregator($rows, [
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
        ]);
    }

    private function rows(
        CfoCommandCenterFilters $filters,
        array $projects,
        array $marginReport,
        array $wipReport,
        array $planFactItems,
        array $calendarItems,
        bool $seedProjects,
    ): array {
        $rows = [];

        if ($seedProjects) {
            foreach ($projects as $project) {
                if (! is_array($project) || ! isset($project['id'])) {
                    continue;
                }

                $currency = $this->currency($filters->currency);
                $rows[$this->key((int) $project['id'], $currency)] = $this->emptyRow($project, $currency, $filters);
            }
        }

        foreach (($marginReport['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || ! isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);
            $actual = is_array($row['actual'] ?? null) ? $row['actual'] : [];
            $forecast = is_array($row['forecast'] ?? null) ? $row['forecast'] : [];
            $target['metrics']['revenue'] = $this->money($actual['revenue'] ?? '0');
            $target['metrics']['cost'] = $this->money($actual['cost'] ?? '0');
            $target['metrics']['gross_margin'] = $this->money($actual['gross_margin'] ?? '0');
            $target['metrics']['forecast_revenue'] = $this->money($forecast['revenue'] ?? '0');
            $target['metrics']['forecast_cost'] = $this->money($forecast['cost'] ?? '0');
            $target['metrics']['forecast_gross_margin'] = $this->money($forecast['gross_margin'] ?? '0');
            $target['drill_down']['project_margin_key'] = $row['drill_down_key'] ?? null;
            $this->rememberSourceRefs($target['source_refs'], $row['source_refs'] ?? []);
            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            unset($target);
        }

        foreach (($wipReport['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || ! isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);

            foreach (['wip', 'wip_total', 'ftc', 'eac', 'ctc', 'forecast_gross_margin'] as $field) {
                $target['metrics'][$field] = $this->money($metrics[$field] ?? '0');
            }

            $target['metrics']['forecast_revenue'] = $this->money(
                $metrics['forecast_revenue'] ?? $metrics['forecast_revenue_at_completion'] ?? $target['metrics']['forecast_revenue'],
            );
            $target['drill_down']['wip_forecast_key'] = $row['drill_down_key'] ?? null;
            $this->rememberSourceRefs($target['source_refs'], $row['source_refs'] ?? []);
            $this->rememberStrings($target['problem_flags'], $row['problem_flags'] ?? []);
            $this->rememberStrings($target['risk_flags'], $row['risk_flags'] ?? []);
            unset($target);
        }

        foreach ($planFactItems as $row) {
            if (! is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            if ($projectId === null || ! isset($projects[$projectId])) {
                continue;
            }

            $currency = $this->currency($row['currency'] ?? $filters->currency);
            $target = &$this->row($rows, $projects[$projectId], $currency, $filters);
            $target['budget_deviation'] = [
                'variance_amount' => $this->money($row['variance_amount'] ?? '0'),
                'risk_level' => $this->riskLevel($row['risk_level'] ?? 'low'),
                'drill_down_key' => $row['drill_down_key'] ?? null,
            ];
            $this->rememberSourceRefs($target['source_refs'], $row['source_refs'] ?? []);

            if (in_array($target['budget_deviation']['risk_level'], ['high', 'critical'], true)) {
                $this->rememberStrings($target['problem_flags'], ['budget_deviation']);
            }

            unset($target);
        }

        foreach ($calendarItems as $item) {
            if (! $item instanceof PaymentCalendarItem || $item->projectId === null || ! isset($projects[$item->projectId])) {
                continue;
            }

            $currency = $this->currency($item->currency);
            $target = &$this->row($rows, $projects[$item->projectId], $currency, $filters);
            $amount = $this->money($item->remainingAmount);

            if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
                $target['cash_gap']['inflows'] = PortfolioDecimal::add($target['cash_gap']['inflows'], $amount);
            } elseif ($item->direction === PaymentCalendarItem::DIRECTION_OUTFLOW) {
                $target['cash_gap']['outflows'] = PortfolioDecimal::add($target['cash_gap']['outflows'], $amount);
            }
            $this->rememberSourceRefs($target['source_refs'], [[
                'type' => $this->calendarSourceType($item->sourceType),
                'id' => $item->sourceId,
            ]]);

            unset($target);
        }

        foreach ($rows as &$row) {
            $this->finalizeRow($row);
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, static function (array $left, array $right): int {
            $risk = self::RISK_RANK[(string) $right['risk_level']]
                <=> self::RISK_RANK[(string) $left['risk_level']];
            if ($risk !== 0) {
                return $risk;
            }
            $score = PortfolioDecimal::compare((string) $right['score'], (string) $left['score']);

            return $score !== 0
                ? $score
                : strcmp((string) ($left['project']['name'] ?? ''), (string) ($right['project']['name'] ?? ''));
        });

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
                'revenue' => '0.00',
                'cost' => '0.00',
                'gross_margin' => '0.00',
                'forecast_revenue' => '0.00',
                'forecast_cost' => '0.00',
                'forecast_gross_margin' => '0.00',
                'wip_total' => '0.00',
                'ftc' => '0.00',
                'eac' => '0.00',
                'ctc' => '0.00',
                'cash_gap_signal' => '0.00',
            ];

            foreach (['revenue', 'cost', 'gross_margin', 'forecast_revenue', 'forecast_cost', 'forecast_gross_margin', 'wip_total', 'ftc', 'eac', 'ctc'] as $field) {
                $byCurrency[$currency][$field] = PortfolioDecimal::add(
                    $byCurrency[$currency][$field],
                    $this->money($row['metrics'][$field] ?? '0'),
                );
            }

            $byCurrency[$currency]['cash_gap_signal'] = PortfolioDecimal::add(
                $byCurrency[$currency]['cash_gap_signal'],
                $this->money($row['cash_gap']['signal'] ?? '0'),
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
            'score' => '0.00',
            'risk_level' => 'low',
            'metrics' => [
                'revenue' => '0.00',
                'cost' => '0.00',
                'gross_margin' => '0.00',
                'forecast_revenue' => '0.00',
                'forecast_cost' => '0.00',
                'forecast_gross_margin' => '0.00',
                'wip' => '0.00',
                'wip_total' => '0.00',
                'ftc' => '0.00',
                'eac' => '0.00',
                'ctc' => '0.00',
                'cash_gap_signal' => '0.00',
            ],
            'budget_deviation' => [
                'variance_amount' => '0.00',
                'risk_level' => 'low',
                'drill_down_key' => null,
            ],
            'cash_gap' => [
                'inflows' => '0.00',
                'outflows' => '0.00',
                'signal' => '0.00',
                'has_gap' => false,
            ],
            'problem_flags' => [],
            'risk_flags' => [],
            'source_refs' => [['type' => 'project', 'id' => (int) $project['id']]],
            'drill_down' => [
                'href' => '/budgeting/project-margin?project_id='.(int) $project['id'],
                'api_href' => '/api/v1/admin/budgeting/project-margin?project_id='.(int) $project['id'],
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
        $signal = PortfolioDecimal::subtract($row['cash_gap']['inflows'], $row['cash_gap']['outflows']);
        $row['cash_gap']['signal'] = $signal;
        $row['cash_gap']['has_gap'] = PortfolioDecimal::isNegative($signal);
        $row['metrics']['cash_gap_signal'] = $signal;

        if ($row['cash_gap']['has_gap']) {
            $this->rememberStrings($row['risk_flags'], ['cash_gap_risk']);
        }

        if (PortfolioDecimal::isNegative($row['metrics']['gross_margin'])
            || PortfolioDecimal::isNegative($row['metrics']['forecast_gross_margin'])) {
            $this->rememberStrings($row['risk_flags'], ['negative_margin']);
        }

        $score = '0.00';
        if ($row['cash_gap']['has_gap']) {
            $score = PortfolioDecimal::add($score, PortfolioDecimal::cashGapRiskPoints($row['cash_gap']['signal']));
        }

        if (in_array('budget_deviation', $row['problem_flags'], true)) {
            $score = PortfolioDecimal::add(
                $score,
                (string) (self::RISK_RANK[(string) $row['budget_deviation']['risk_level']] * 10),
            );
        }

        if (in_array('negative_margin', $row['risk_flags'], true)) {
            $score = PortfolioDecimal::add($score, '90.00');
        }

        $riskLevel = 'low';
        if ($row['cash_gap']['has_gap']) {
            $riskLevel = $this->highestRisk($riskLevel, 'high');
        }

        $riskLevel = $this->highestRisk($riskLevel, (string) $row['budget_deviation']['risk_level']);
        if (in_array('negative_margin', $row['risk_flags'], true)) {
            $riskLevel = $this->highestRisk($riskLevel, 'critical');
        }

        $row['score'] = $score;
        $row['risk_level'] = $riskLevel;
        $row['problem_flags'] = array_values(array_unique($row['problem_flags']));
        $row['risk_flags'] = array_values(array_unique($row['risk_flags']));
        $row['source_refs'] = array_values($row['source_refs']);
    }

    private function projectId(array $row): ?int
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : [];
        $id = $project['id'] ?? $row['project_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function key(int $projectId, string $currency): string
    {
        return $projectId.'|'.$currency;
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
        if (! is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $target[] = $value;
            }
        }
    }

    private function rememberSourceRefs(array &$target, mixed $values): void
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return;
        }

        foreach ($values as $value) {
            if (! is_array($value)
                || ! is_string($value['type'] ?? null)
                || (! is_int($value['id'] ?? null) && ! is_string($value['id'] ?? null))
                || trim((string) $value['id']) === '') {
                continue;
            }

            $id = $value['id'];
            if (is_int($id) && $id < 1) {
                continue;
            }
            $target[$value['type'].':'.(string) $id] = ['type' => $value['type'], 'id' => $id];
        }
    }

    private function calendarSourceType(string $sourceType): string
    {
        return match ($sourceType) {
            'budget_limit_reservation' => 'budget_reservation',
            'budget_amount' => 'budget_plan',
            default => $sourceType,
        };
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

    private function money(mixed $amount): string
    {
        if (is_int($amount) || is_string($amount)) {
            return PortfolioDecimal::money($amount);
        }
        if (is_float($amount) && is_finite($amount)) {
            return PortfolioDecimal::money(rtrim(rtrim(sprintf('%.14F', $amount), '0'), '.'));
        }

        return PortfolioDecimal::money('0');
    }
}
