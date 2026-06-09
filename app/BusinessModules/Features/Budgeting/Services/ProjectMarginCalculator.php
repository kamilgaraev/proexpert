<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceAggregate;

final class ProjectMarginCalculator
{
    /**
     * @param list<ProjectMarginSourceAggregate> $aggregates
     * @param list<array<string, mixed>> $sourcesCoverage
     * @param list<string> $warnings
     */
    public function calculate(
        ProjectMarginReportFilters $filters,
        array $aggregates,
        ProjectMarginDimensions $dimensions,
        ?array $scenario,
        ?array $budgetVersion,
        array $sourcesCoverage,
        array $warnings,
        array $meta = [],
    ): array {
        $buckets = $this->groupAggregates($filters, $aggregates);
        $rows = $this->rows($filters, $buckets, $dimensions);
        $totalsByCurrency = $this->totalsByCurrency($rows);

        return [
            'filters' => $filters->toArray(),
            'period' => $filters->period(),
            'summary' => $this->summary($rows, $totalsByCurrency),
            'totals_by_currency' => $totalsByCurrency,
            'rows' => $rows,
            'groups' => $this->groups($filters),
            'drill_down_available' => true,
            'sources_coverage' => $sourcesCoverage,
            'warnings' => array_values(array_unique($warnings)),
            'meta' => array_merge($meta, [
                'budget_version' => $budgetVersion,
                'scenario' => $scenario,
                'source_of_truth' => [
                    'management' => 'prohelper',
                    'plan' => 'budget_amounts',
                    'actual_revenue' => 'contract_performance_acts_and_completed_works',
                    'actual_cost' => 'payment_documents_warehouse_movements_time_entries',
                    'confirmation' => 'bank_edo_1c_reconciliation',
                    'accounting' => 'external_confirmation_only',
                ],
                'freshness' => [
                    'status' => $this->freshnessStatus($sourcesCoverage),
                    'generated_at' => $meta['generated_at'] ?? null,
                ],
                'reconciliation' => [
                    'status' => $this->reconciliationStatus($rows),
                    'external_systems' => ['1c', 'bank', 'edo'],
                ],
            ]),
        ];
    }

    /**
     * @param list<ProjectMarginSourceAggregate> $aggregates
     * @return array<string, array<string, mixed>>
     */
    private function groupAggregates(ProjectMarginReportFilters $filters, array $aggregates): array
    {
        $buckets = [];

        foreach ($aggregates as $aggregate) {
            $parts = $this->groupParts($filters->groupBy, $aggregate);
            $key = json_encode($parts, JSON_THROW_ON_ERROR);

            $buckets[$key] ??= [
                'group' => $parts,
                'plan_revenue' => 0.0,
                'plan_cost' => 0.0,
                'forecast_revenue' => 0.0,
                'forecast_cost' => 0.0,
                'actual_revenue' => 0.0,
                'actual_cost' => 0.0,
                'currencies' => [],
                'budget_article_ids' => [],
                'responsibility_center_ids' => [],
                'project_ids' => [],
                'contract_ids' => [],
                'counterparty_ids' => [],
                'source_types' => [],
                'problem_flags' => [],
                'risk_flags' => [],
                'quality_status' => 'actual',
                'source_rows_count' => 0,
            ];

            $buckets[$key]['plan_revenue'] = $this->money($buckets[$key]['plan_revenue'] + $aggregate->planRevenue);
            $buckets[$key]['plan_cost'] = $this->money($buckets[$key]['plan_cost'] + $aggregate->planCost);
            $buckets[$key]['forecast_revenue'] = $this->money($buckets[$key]['forecast_revenue'] + $aggregate->forecastRevenue);
            $buckets[$key]['forecast_cost'] = $this->money($buckets[$key]['forecast_cost'] + $aggregate->forecastCost);
            $buckets[$key]['actual_revenue'] = $this->money($buckets[$key]['actual_revenue'] + $aggregate->actualRevenue);
            $buckets[$key]['actual_cost'] = $this->money($buckets[$key]['actual_cost'] + $aggregate->actualCost);

            $buckets[$key]['currencies'][$aggregate->currency] = true;
            $buckets[$key]['source_rows_count'] += $aggregate->sourceRowsCount;
            $buckets[$key]['quality_status'] = $this->worstQuality($buckets[$key]['quality_status'], $aggregate->qualityStatus);

            $this->rememberId($buckets[$key]['budget_article_ids'], $aggregate->budgetArticleId);
            $this->rememberId($buckets[$key]['responsibility_center_ids'], $aggregate->responsibilityCenterId);
            $this->rememberId($buckets[$key]['project_ids'], $aggregate->projectId);
            $this->rememberId($buckets[$key]['contract_ids'], $aggregate->contractId);
            $this->rememberId($buckets[$key]['counterparty_ids'], $aggregate->counterpartyId);
            $this->rememberStrings($buckets[$key]['source_types'], $aggregate->sourceTypes);
            $this->rememberStrings($buckets[$key]['problem_flags'], $aggregate->problemFlags);
            $this->rememberStrings($buckets[$key]['risk_flags'], $aggregate->riskFlags);
        }

        return $buckets;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function rows(ProjectMarginReportFilters $filters, array $buckets, ProjectMarginDimensions $dimensions): array
    {
        $rows = [];

        foreach ($buckets as $bucket) {
            $group = $bucket['group'];
            $budgetArticleId = $this->singleGroupedId($filters, $group, ProjectMarginReportFilters::GROUP_BUDGET_ARTICLE, $bucket['budget_article_ids']);
            $responsibilityCenterId = $this->singleGroupedId($filters, $group, ProjectMarginReportFilters::GROUP_RESPONSIBILITY_CENTER, $bucket['responsibility_center_ids']);
            $projectId = $this->singleGroupedId($filters, $group, ProjectMarginReportFilters::GROUP_PROJECT, $bucket['project_ids']);
            $contractId = $this->singleGroupedId($filters, $group, ProjectMarginReportFilters::GROUP_CONTRACT, $bucket['contract_ids']);
            $counterpartyId = $this->singleGroupedId($filters, $group, ProjectMarginReportFilters::GROUP_COUNTERPARTY, $bucket['counterparty_ids']);
            $currency = $this->groupedCurrency($group, $bucket['currencies']);
            $plan = $this->marginBlock((float) $bucket['plan_revenue'], (float) $bucket['plan_cost']);
            $forecast = $this->marginBlock((float) $bucket['forecast_revenue'], (float) $bucket['forecast_cost']);
            $actual = $this->marginBlock((float) $bucket['actual_revenue'], (float) $bucket['actual_cost']);
            $variance = $this->varianceBlock($plan, $actual);

            $rows[] = [
                'group' => $group,
                'budget_article' => $dimensions->article($budgetArticleId),
                'responsibility_center' => $dimensions->responsibilityCenter($responsibilityCenterId),
                'project' => $dimensions->project($projectId),
                'contract' => $dimensions->contract($contractId),
                'counterparty' => $dimensions->counterparty($counterpartyId),
                'currency' => $currency,
                'plan' => $plan,
                'forecast' => $forecast,
                'actual' => $actual,
                'variance' => $variance,
                'source_types' => array_keys($bucket['source_types']),
                'problem_flags' => array_keys($bucket['problem_flags']),
                'risk_flags' => array_keys($bucket['risk_flags']),
                'quality_status' => $bucket['quality_status'],
                'source_rows_count' => (int) $bucket['source_rows_count'],
                'drill_down_key' => ProjectMarginDrillDownKey::encode($filters->groupBy, $group),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [
                $left['currency'],
                (string) ($left['group'][ProjectMarginReportFilters::GROUP_MONTH] ?? ''),
                (string) ($left['project']['name'] ?? ''),
                (string) ($left['contract']['number'] ?? ''),
                (string) ($left['budget_article']['code'] ?? ''),
            ] <=> [
                $right['currency'],
                (string) ($right['group'][ProjectMarginReportFilters::GROUP_MONTH] ?? ''),
                (string) ($right['project']['name'] ?? ''),
                (string) ($right['contract']['number'] ?? ''),
                (string) ($right['budget_article']['code'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function totalsByCurrency(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] ??= $this->emptyTotal($currency);

            foreach (['plan', 'forecast', 'actual'] as $section) {
                foreach (['revenue', 'cost'] as $field) {
                    $totals[$currency][$section][$field] = $this->money(
                        $totals[$currency][$section][$field] + (float) ($row[$section][$field] ?? 0)
                    );
                }
            }

            $this->rememberStrings($totals[$currency]['problem_flags'], $row['problem_flags']);
            $this->rememberStrings($totals[$currency]['risk_flags'], $row['risk_flags']);
            $totals[$currency]['quality_status'] = $this->worstQuality(
                $totals[$currency]['quality_status'],
                (string) $row['quality_status'],
            );
            $totals[$currency]['rows_count']++;
        }

        ksort($totals);

        return array_map(function (array $total): array {
            foreach (['plan', 'forecast', 'actual'] as $section) {
                $total[$section] = $this->marginBlock($total[$section]['revenue'], $total[$section]['cost']);
            }

            $total['variance'] = $this->varianceBlock($total['plan'], $total['actual']);
            $total['problem_flags'] = array_keys($total['problem_flags']);
            $total['risk_flags'] = array_keys($total['risk_flags']);

            return $total;
        }, array_values($totals));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $totalsByCurrency
     */
    private function summary(array $rows, array $totalsByCurrency): array
    {
        $qualityStatus = 'actual';
        $problemFlags = [];
        $riskFlags = [];

        foreach ($totalsByCurrency as $total) {
            $qualityStatus = $this->worstQuality($qualityStatus, (string) $total['quality_status']);
            $this->rememberStrings($problemFlags, $total['problem_flags']);
            $this->rememberStrings($riskFlags, $total['risk_flags']);
        }

        return [
            'rows_count' => count($rows),
            'currencies' => array_map(static fn (array $total): string => (string) $total['currency'], $totalsByCurrency),
            'quality_status' => $qualityStatus,
            'has_actuals' => array_reduce($rows, static fn (bool $carry, array $row): bool => (
                $carry || (float) ($row['actual']['revenue'] ?? 0) > 0.0 || (float) ($row['actual']['cost'] ?? 0) > 0.0
            ), false),
            'has_plan' => array_reduce($rows, static fn (bool $carry, array $row): bool => (
                $carry || (float) ($row['plan']['revenue'] ?? 0) > 0.0 || (float) ($row['plan']['cost'] ?? 0) > 0.0
            ), false),
            'problem_flags' => array_keys($problemFlags),
            'risk_flags' => array_keys($riskFlags),
        ];
    }

    private function emptyTotal(string $currency): array
    {
        return [
            'currency' => $currency,
            'plan' => ['revenue' => 0.0, 'cost' => 0.0],
            'forecast' => ['revenue' => 0.0, 'cost' => 0.0],
            'actual' => ['revenue' => 0.0, 'cost' => 0.0],
            'variance' => [],
            'problem_flags' => [],
            'risk_flags' => [],
            'quality_status' => 'actual',
            'rows_count' => 0,
        ];
    }

    private function marginBlock(float $revenue, float $cost): array
    {
        $grossMargin = $this->money($revenue - $cost);

        return [
            'revenue' => $this->money($revenue),
            'cost' => $this->money($cost),
            'gross_margin' => $grossMargin,
            'margin_percent' => $this->percent($grossMargin, $revenue),
        ];
    }

    private function varianceBlock(array $plan, array $actual): array
    {
        return [
            'revenue' => $this->money((float) $actual['revenue'] - (float) $plan['revenue']),
            'cost' => $this->money((float) $plan['cost'] - (float) $actual['cost']),
            'gross_margin' => $this->money((float) $actual['gross_margin'] - (float) $plan['gross_margin']),
            'margin_percent' => $this->percentDelta($plan['margin_percent'], $actual['margin_percent']),
        ];
    }

    /**
     * @param list<string> $groupBy
     * @return array<string, mixed>
     */
    private function groupParts(array $groupBy, ProjectMarginSourceAggregate $aggregate): array
    {
        $parts = [];

        foreach ($groupBy as $group) {
            $parts[$group] = match ($group) {
                ProjectMarginReportFilters::GROUP_MONTH => $aggregate->periodMonth !== null ? mb_substr($aggregate->periodMonth, 0, 7) : null,
                ProjectMarginReportFilters::GROUP_BUDGET_ARTICLE => $aggregate->budgetArticleId,
                ProjectMarginReportFilters::GROUP_RESPONSIBILITY_CENTER => $aggregate->responsibilityCenterId,
                ProjectMarginReportFilters::GROUP_PROJECT => $aggregate->projectId,
                ProjectMarginReportFilters::GROUP_CONTRACT => $aggregate->contractId,
                ProjectMarginReportFilters::GROUP_COUNTERPARTY => $aggregate->counterpartyId,
                ProjectMarginReportFilters::GROUP_CURRENCY => $aggregate->currency,
                default => null,
            };
        }

        return $parts;
    }

    private function singleGroupedId(ProjectMarginReportFilters $filters, array $groupParts, string $group, array $ids): ?int
    {
        if (in_array($group, $filters->groupBy, true)) {
            $value = $groupParts[$group] ?? null;

            return is_numeric($value) ? (int) $value : null;
        }

        $value = $this->groupValue($filters, $group);
        if (is_numeric($value)) {
            return (int) $value;
        }

        return count($ids) === 1 ? (int) array_key_first($ids) : null;
    }

    private function groupValue(ProjectMarginReportFilters $filters, string $group): mixed
    {
        return match ($group) {
            ProjectMarginReportFilters::GROUP_BUDGET_ARTICLE => $filters->budgetArticleId,
            ProjectMarginReportFilters::GROUP_RESPONSIBILITY_CENTER => $filters->responsibilityCenterId,
            ProjectMarginReportFilters::GROUP_PROJECT => $filters->projectId,
            ProjectMarginReportFilters::GROUP_CONTRACT => $filters->contractId,
            ProjectMarginReportFilters::GROUP_COUNTERPARTY => $filters->counterpartyId,
            default => null,
        };
    }

    private function groupedCurrency(array $group, array $currencies): string
    {
        if (isset($group[ProjectMarginReportFilters::GROUP_CURRENCY]) && is_string($group[ProjectMarginReportFilters::GROUP_CURRENCY])) {
            return mb_strtoupper($group[ProjectMarginReportFilters::GROUP_CURRENCY]);
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : 'MIXED';
    }

    private function rememberId(array &$ids, ?int $id): void
    {
        if ($id !== null) {
            $ids[$id] = true;
        }
    }

    /**
     * @param list<string> $values
     */
    private function rememberStrings(array &$target, array $values): void
    {
        foreach ($values as $value) {
            if ($value !== '') {
                $target[$value] = true;
            }
        }
    }

    private function groups(ProjectMarginReportFilters $filters): array
    {
        return array_map(static fn (string $group): array => [
            'key' => $group,
            'selected' => in_array($group, $filters->groupBy, true),
        ], ProjectMarginReportFilters::ALLOWED_GROUP_BY);
    }

    private function freshnessStatus(array $sourcesCoverage): string
    {
        foreach ($sourcesCoverage as $source) {
            if (($source['available'] ?? true) === false) {
                return 'partial';
            }
        }

        return 'actual';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function reconciliationStatus(array $rows): string
    {
        foreach ($rows as $row) {
            if (in_array('accrual_without_payment', $row['risk_flags'] ?? [], true)) {
                return 'attention';
            }
        }

        return 'actual';
    }

    private function worstQuality(string $left, string $right): string
    {
        $rank = [
            'actual' => 0,
            'attention' => 1,
            'partial' => 2,
            'unavailable' => 3,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function percent(float $numerator, float $denominator): ?float
    {
        if (abs($denominator) < 0.01) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function percentDelta(mixed $planPercent, mixed $actualPercent): ?float
    {
        if (!is_numeric($planPercent) || !is_numeric($actualPercent)) {
            return null;
        }

        return round((float) $actualPercent - (float) $planPercent, 2);
    }
}
