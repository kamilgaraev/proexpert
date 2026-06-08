<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\PlanFactCurrencyTotal;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportResult;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportRow;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceAggregate;

final class PlanFactCalculator
{
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';

    /**
     * @param list<PlanFactSourceAggregate> $aggregates
     * @param list<array<string, mixed>> $sourcesCoverage
     * @param list<string> $warnings
     */
    public function calculate(
        PlanFactReportFilters $filters,
        array $aggregates,
        PlanFactDimensions $dimensions,
        array $scenario,
        array $budgetVersion,
        array $sourcesCoverage,
        array $warnings,
        array $meta = [],
    ): PlanFactReportResult {
        $buckets = $this->groupAggregates($filters, $aggregates, $dimensions);
        $rows = $this->rows($filters, $buckets, $dimensions, $scenario);
        $totalsByCurrency = $this->totalsByCurrency($rows);

        return new PlanFactReportResult(
            filters: $filters->toArray(),
            period: $filters->period(),
            summary: $this->summary($rows, $totalsByCurrency),
            totalsByCurrency: $totalsByCurrency,
            rows: $rows,
            groups: $this->groups($filters),
            drillDownAvailable: true,
            sourcesCoverage: $sourcesCoverage,
            warnings: array_values(array_unique($warnings)),
            meta: array_merge($meta, [
                'budget_version' => $budgetVersion,
                'scenario' => $scenario,
                'source_of_truth' => [
                    'plan' => 'budget_amounts.plan_amount',
                    'forecast' => 'budget_amounts.forecast_amount',
                    'actual' => 'payment_transactions.completed',
                    'commitment' => 'budget_limit_reservations_and_active_payment_documents',
                    'accounting' => 'management_only',
                ],
            ]),
        );
    }

    /**
     * @param list<PlanFactSourceAggregate> $aggregates
     * @return array<string, array<string, mixed>>
     */
    private function groupAggregates(
        PlanFactReportFilters $filters,
        array $aggregates,
        PlanFactDimensions $dimensions,
    ): array {
        $buckets = [];

        foreach ($aggregates as $aggregate) {
            $parts = $this->groupParts($filters->groupBy, $aggregate);
            $key = json_encode($parts, JSON_THROW_ON_ERROR);
            $flowDirection = $aggregate->flowDirection ?? $dimensions->flowDirection($aggregate->budgetArticleId);

            $buckets[$key] ??= [
                'group' => $parts,
                'plan_amount' => 0.0,
                'forecast_amount' => 0.0,
                'actual_amount' => 0.0,
                'committed_amount' => 0.0,
                'variance_amount' => 0.0,
                'flow_buckets' => [],
                'currencies' => [],
                'budget_article_ids' => [],
                'responsibility_center_ids' => [],
                'project_ids' => [],
                'counterparty_ids' => [],
            ];

            $buckets[$key]['plan_amount'] = $this->money($buckets[$key]['plan_amount'] + $aggregate->planAmount);
            $buckets[$key]['forecast_amount'] = $this->money($buckets[$key]['forecast_amount'] + $aggregate->forecastAmount);
            $buckets[$key]['actual_amount'] = $this->money($buckets[$key]['actual_amount'] + $aggregate->actualAmount);
            $buckets[$key]['committed_amount'] = $this->money($buckets[$key]['committed_amount'] + $aggregate->committedAmount);
            $buckets[$key]['variance_amount'] = $this->money(
                $buckets[$key]['variance_amount'] + $this->varianceContribution($aggregate, $flowDirection)
            );
            $this->rememberFlowAmounts($buckets[$key]['flow_buckets'], $aggregate, $flowDirection);
            $buckets[$key]['currencies'][$aggregate->currency] = true;

            $this->rememberId($buckets[$key]['budget_article_ids'], $aggregate->budgetArticleId);
            $this->rememberId($buckets[$key]['responsibility_center_ids'], $aggregate->responsibilityCenterId);
            $this->rememberId($buckets[$key]['project_ids'], $aggregate->projectId);
            $this->rememberId($buckets[$key]['counterparty_ids'], $aggregate->counterpartyId);
        }

        return $buckets;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<PlanFactReportRow>
     */
    private function rows(
        PlanFactReportFilters $filters,
        array $buckets,
        PlanFactDimensions $dimensions,
        array $scenario,
    ): array {
        $rows = [];

        foreach ($buckets as $bucket) {
            $group = $bucket['group'];
            $budgetArticleId = $this->singleGroupedId($filters, PlanFactReportFilters::GROUP_BUDGET_ARTICLE, $bucket['budget_article_ids']);
            $responsibilityCenterId = $this->singleGroupedId($filters, PlanFactReportFilters::GROUP_RESPONSIBILITY_CENTER, $bucket['responsibility_center_ids']);
            $projectId = $this->singleGroupedId($filters, PlanFactReportFilters::GROUP_PROJECT, $bucket['project_ids']);
            $counterpartyId = $this->singleId($bucket['counterparty_ids']);
            $currency = $this->groupedCurrency($group, $bucket['currencies']);
            $planAmount = $this->money($bucket['plan_amount']);
            $varianceAmount = $this->money($bucket['variance_amount']);
            $unfavorableAmount = $this->unfavorableAmount($bucket['flow_buckets']);

            $rows[] = new PlanFactReportRow(
                group: $group,
                budgetArticle: $dimensions->article($budgetArticleId),
                responsibilityCenter: $dimensions->responsibilityCenter($responsibilityCenterId),
                project: $dimensions->project($projectId),
                counterparty: $dimensions->counterparty($counterpartyId),
                scenario: $scenario,
                currency: $currency,
                planAmount: $planAmount,
                forecastAmount: $this->money($bucket['forecast_amount']),
                actualAmount: $this->money($bucket['actual_amount']),
                committedAmount: $this->money($bucket['committed_amount']),
                varianceAmount: $varianceAmount,
                variancePercent: $this->percent($varianceAmount, $planAmount),
                riskLevel: $this->riskLevel($planAmount, $unfavorableAmount),
                drillDownKey: PlanFactDrillDownKey::encode($filters->groupBy, $group),
            );
        }

        usort($rows, function (PlanFactReportRow $left, PlanFactReportRow $right): int {
            return [
                $left->currency,
                (string) ($left->group[PlanFactReportFilters::GROUP_MONTH] ?? ''),
                (string) ($left->budgetArticle['code'] ?? ''),
                (string) ($left->responsibilityCenter['code'] ?? ''),
                (string) ($left->project['name'] ?? ''),
            ] <=> [
                $right->currency,
                (string) ($right->group[PlanFactReportFilters::GROUP_MONTH] ?? ''),
                (string) ($right->budgetArticle['code'] ?? ''),
                (string) ($right->responsibilityCenter['code'] ?? ''),
                (string) ($right->project['name'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * @param list<PlanFactReportRow> $rows
     * @return list<PlanFactCurrencyTotal>
     */
    private function totalsByCurrency(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->currency] ??= [
                'plan_amount' => 0.0,
                'forecast_amount' => 0.0,
                'actual_amount' => 0.0,
                'committed_amount' => 0.0,
                'variance_amount' => 0.0,
                'risk_level' => self::RISK_LOW,
                'rows_count' => 0,
            ];

            $totals[$row->currency]['plan_amount'] = $this->money($totals[$row->currency]['plan_amount'] + $row->planAmount);
            $totals[$row->currency]['forecast_amount'] = $this->money($totals[$row->currency]['forecast_amount'] + $row->forecastAmount);
            $totals[$row->currency]['actual_amount'] = $this->money($totals[$row->currency]['actual_amount'] + $row->actualAmount);
            $totals[$row->currency]['committed_amount'] = $this->money($totals[$row->currency]['committed_amount'] + $row->committedAmount);
            $totals[$row->currency]['variance_amount'] = $this->money($totals[$row->currency]['variance_amount'] + $row->varianceAmount);
            $totals[$row->currency]['risk_level'] = $this->highestRisk($totals[$row->currency]['risk_level'], $row->riskLevel);
            $totals[$row->currency]['rows_count']++;
        }

        ksort($totals);

        $result = [];
        foreach ($totals as $currency => $total) {
            $result[] = new PlanFactCurrencyTotal(
                currency: (string) $currency,
                planAmount: $this->money($total['plan_amount']),
                forecastAmount: $this->money($total['forecast_amount']),
                actualAmount: $this->money($total['actual_amount']),
                committedAmount: $this->money($total['committed_amount']),
                varianceAmount: $this->money($total['variance_amount']),
                variancePercent: $this->percent((float) $total['variance_amount'], (float) $total['plan_amount']),
                riskLevel: (string) $total['risk_level'],
                rowsCount: (int) $total['rows_count'],
            );
        }

        return $result;
    }

    /**
     * @param list<PlanFactReportRow> $rows
     * @param list<PlanFactCurrencyTotal> $totalsByCurrency
     */
    private function summary(array $rows, array $totalsByCurrency): array
    {
        $riskLevel = self::RISK_LOW;
        foreach ($totalsByCurrency as $total) {
            $riskLevel = $this->highestRisk($riskLevel, $total->riskLevel);
        }

        return [
            'rows_count' => count($rows),
            'currencies' => array_map(static fn (PlanFactCurrencyTotal $total): string => $total->currency, $totalsByCurrency),
            'highest_risk_level' => $riskLevel,
            'has_actuals' => array_reduce($rows, static fn (bool $carry, PlanFactReportRow $row): bool => $carry || $row->actualAmount > 0.0, false),
            'has_commitments' => array_reduce($rows, static fn (bool $carry, PlanFactReportRow $row): bool => $carry || $row->committedAmount > 0.0, false),
        ];
    }

    private function groups(PlanFactReportFilters $filters): array
    {
        $groups = [];

        foreach (PlanFactReportFilters::ALLOWED_GROUP_BY as $group) {
            $groups[] = [
                'key' => $group,
                'selected' => in_array($group, $filters->groupBy, true),
            ];
        }

        return $groups;
    }

    /**
     * @param list<string> $groupBy
     */
    private function groupParts(array $groupBy, PlanFactSourceAggregate $aggregate): array
    {
        $parts = [];

        foreach ($groupBy as $group) {
            $parts[$group] = match ($group) {
                PlanFactReportFilters::GROUP_MONTH => $aggregate->month,
                PlanFactReportFilters::GROUP_BUDGET_ARTICLE => $aggregate->budgetArticleId,
                PlanFactReportFilters::GROUP_RESPONSIBILITY_CENTER => $aggregate->responsibilityCenterId,
                PlanFactReportFilters::GROUP_PROJECT => $aggregate->projectId,
                PlanFactReportFilters::GROUP_CURRENCY => $aggregate->currency,
                default => null,
            };
        }

        return $parts;
    }

    private function varianceContribution(PlanFactSourceAggregate $aggregate, ?string $flowDirection): float
    {
        if ($this->isIncomeDirection($flowDirection)) {
            return $this->money($aggregate->actualAmount - $aggregate->planAmount);
        }

        return $this->money($aggregate->planAmount - $aggregate->actualAmount);
    }

    private function isIncomeDirection(?string $flowDirection): bool
    {
        return in_array($flowDirection, ['income', 'inflow'], true);
    }

    private function rememberFlowAmounts(array &$flowBuckets, PlanFactSourceAggregate $aggregate, ?string $flowDirection): void
    {
        $flowKey = $this->isIncomeDirection($flowDirection) ? 'income' : 'outflow';

        $flowBuckets[$flowKey] ??= [
            'plan_amount' => 0.0,
            'actual_amount' => 0.0,
            'committed_amount' => 0.0,
        ];

        $flowBuckets[$flowKey]['plan_amount'] = $this->money($flowBuckets[$flowKey]['plan_amount'] + $aggregate->planAmount);
        $flowBuckets[$flowKey]['actual_amount'] = $this->money($flowBuckets[$flowKey]['actual_amount'] + $aggregate->actualAmount);
        $flowBuckets[$flowKey]['committed_amount'] = $this->money($flowBuckets[$flowKey]['committed_amount'] + $aggregate->committedAmount);
    }

    private function unfavorableAmount(array $flowBuckets): float
    {
        $amount = 0.0;

        foreach ($flowBuckets as $flowKey => $bucket) {
            $planAmount = (float) ($bucket['plan_amount'] ?? 0.0);
            $actualAmount = (float) ($bucket['actual_amount'] ?? 0.0);
            $committedAmount = (float) ($bucket['committed_amount'] ?? 0.0);

            $amount += $flowKey === 'income'
                ? max(0.0, $planAmount - $actualAmount)
                : max(0.0, $actualAmount + $committedAmount - $planAmount);
        }

        return $this->money($amount);
    }

    private function riskLevel(float $planAmount, float $unfavorableAmount): string
    {
        if ($unfavorableAmount <= 0.0) {
            return self::RISK_LOW;
        }

        if ($planAmount <= 0.0) {
            return self::RISK_CRITICAL;
        }

        $ratio = $unfavorableAmount / abs($planAmount);

        if ($ratio >= 0.25) {
            return self::RISK_CRITICAL;
        }

        if ($ratio >= 0.1) {
            return self::RISK_HIGH;
        }

        return self::RISK_MEDIUM;
    }

    private function highestRisk(string $left, string $right): string
    {
        $rank = [
            self::RISK_LOW => 1,
            self::RISK_MEDIUM => 2,
            self::RISK_HIGH => 3,
            self::RISK_CRITICAL => 4,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private function percent(float $amount, float $base): ?float
    {
        if (abs($base) < 0.01) {
            return null;
        }

        return $this->money(($amount / abs($base)) * 100);
    }

    private function singleGroupedId(PlanFactReportFilters $filters, string $group, array $ids): ?int
    {
        if (!in_array($group, $filters->groupBy, true)) {
            return null;
        }

        return $this->singleId($ids);
    }

    private function singleId(array $ids): ?int
    {
        $values = array_keys(array_filter($ids));

        return count($values) === 1 ? (int) $values[0] : null;
    }

    private function groupedCurrency(array $group, array $currencies): string
    {
        if (isset($group[PlanFactReportFilters::GROUP_CURRENCY]) && is_string($group[PlanFactReportFilters::GROUP_CURRENCY])) {
            return $group[PlanFactReportFilters::GROUP_CURRENCY];
        }

        $values = array_keys(array_filter($currencies));

        return count($values) === 1 ? (string) $values[0] : 'MIXED';
    }

    private function rememberId(array &$ids, ?int $id): void
    {
        if ($id !== null) {
            $ids[$id] = true;
        }
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
