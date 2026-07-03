<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastManualAdjustment;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastSourceAggregate;

final class WipForecastCalculator
{
    /**
     * @param list<WipForecastSourceAggregate> $aggregates
     * @param list<WipForecastManualAdjustment> $adjustments
     * @param list<array<string, mixed>> $assumptions
     * @param list<array<string, mixed>> $sourceCoverage
     * @param list<array<string, mixed>> $freshness
     */
    public function calculate(
        WipForecastReportFilters $filters,
        array $aggregates,
        WipForecastDimensions $dimensions,
        ?array $scenario,
        ?array $budgetVersion,
        ?array $forecastVersion,
        array $adjustments = [],
        array $assumptions = [],
        array $sourceCoverage = [],
        array $freshness = [],
        array $meta = [],
    ): array {
        $buckets = $this->groupAggregates($filters, $aggregates);
        $this->applyAdjustments($filters, $buckets, $adjustments);

        $rows = $this->rows($filters, $buckets, $dimensions);
        $summary = $this->summary($rows);

        return [
            'filters' => $filters->toArray(),
            'period' => $filters->period(),
            'summary' => $summary,
            'totals_by_currency' => $summary['totals_by_currency'],
            'rows' => $rows,
            'formulas' => $this->formulas(),
            'assumptions' => $assumptions,
            'source_coverage' => $sourceCoverage,
            'freshness' => $freshness === [] ? $this->freshness($sourceCoverage, $meta) : $freshness,
            'problem_flags' => $summary['problem_flags'],
            'risk_flags' => $summary['risk_flags'],
            'drill_down' => [
                'available' => true,
                'endpoint' => '/api/v1/admin/budgeting/wip-forecast/drill-down',
            ],
            'actions' => $this->actions($forecastVersion, $meta['permissions'] ?? []),
            'meta' => array_merge($meta, [
                'budget_version' => $budgetVersion,
                'forecast_version' => $forecastVersion,
                'scenario' => $scenario,
                'source_of_truth' => [
                    'management_budget' => 'most',
                    'management_actual_cost' => 'accrual_sources',
                    'cash_payments' => 'reconciliation_only',
                    'bank_1c_edo' => 'confirmation_only',
                    'closed_periods_policy' => 'no_retroactive_rewrite',
                ],
                'comparison' => $meta['comparison'] ?? [
                    'active_forecast' => null,
                    'previous_forecast' => null,
                    'approved_budget' => $budgetVersion,
                ],
            ]),
        ];
    }

    /**
     * @param list<WipForecastSourceAggregate> $aggregates
     * @return array<string, array<string, mixed>>
     */
    private function groupAggregates(WipForecastReportFilters $filters, array $aggregates): array
    {
        $buckets = [];

        foreach ($aggregates as $aggregate) {
            $parts = $this->groupParts($filters->groupBy, $aggregate);
            $key = json_encode($parts, JSON_THROW_ON_ERROR);

            $buckets[$key] ??= $this->emptyBucket($parts);
            $bucket = &$buckets[$key];

            foreach ([
                'bac' => $aggregate->bac,
                'pv' => $aggregate->plannedValue,
                'approved_act_value' => $aggregate->approvedActValue,
                'ac' => $aggregate->actualCostAccrual,
                'cash_only_payments_excluded' => $aggregate->cashOnlyPayments,
                'bottom_up_etc' => $aggregate->bottomUpEtc,
                'forecast_revenue' => $aggregate->forecastRevenue,
            ] as $field => $value) {
                $bucket[$field] = $this->money((float) $bucket[$field] + $value);
            }

            $earnedValue = $aggregate->earnedValueAmount > 0.0
                ? $aggregate->earnedValueAmount
                : $aggregate->bac * max(0.0, min(100.0, (float) ($aggregate->percentComplete ?? 0.0))) / 100;
            $bucket['ev'] = $this->money((float) $bucket['ev'] + $earnedValue);

            $this->rememberId($bucket['project_ids'], $aggregate->projectId);
            $this->rememberId($bucket['stage_ids'], $aggregate->stageId);
            $this->rememberId($bucket['contract_ids'], $aggregate->contractId);
            $this->rememberId($bucket['estimate_item_ids'], $aggregate->estimateItemId);
            $this->rememberStrings($bucket['source_types'], $aggregate->sourceTypes);
            $this->rememberStrings($bucket['problem_flags'], $aggregate->problemFlags);
            $this->rememberStrings($bucket['risk_flags'], $aggregate->riskFlags);
            $bucket['currencies'][$aggregate->currency] = true;
            $bucket['source_rows_count'] += $aggregate->sourceRowsCount;
            $bucket['source_row_refs'] = array_merge($bucket['source_row_refs'], $aggregate->sourceRowRefs);
            $bucket['quality_status'] = $this->worstQuality((string) $bucket['quality_status'], $aggregate->qualityStatus);
            unset($bucket);
        }

        return $buckets;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @param list<WipForecastManualAdjustment> $adjustments
     */
    private function applyAdjustments(WipForecastReportFilters $filters, array &$buckets, array $adjustments): void
    {
        foreach ($adjustments as $adjustment) {
            if (!$adjustment->isApplicable()) {
                continue;
            }

            $aggregate = new WipForecastSourceAggregate(
                periodMonth: $adjustment->periodMonth,
                projectId: $adjustment->projectId,
                stageId: $adjustment->stageId,
                contractId: $adjustment->contractId,
                estimateItemId: $adjustment->estimateItemId,
                currency: $adjustment->currency,
                bac: 0.0,
                plannedValue: 0.0,
                percentComplete: null,
                earnedValueAmount: 0.0,
                approvedActValue: 0.0,
                actualCostAccrual: 0.0,
                cashOnlyPayments: 0.0,
                bottomUpEtc: 0.0,
                forecastRevenue: 0.0,
                sourceTypes: ['manual_adjustment'],
                problemFlags: [],
                riskFlags: [],
                qualityStatus: 'attention',
                sourceRowsCount: 1,
            );
            $parts = $this->groupParts($filters->groupBy, $aggregate);
            $key = json_encode($parts, JSON_THROW_ON_ERROR);
            $buckets[$key] ??= $this->emptyBucket($parts);

            $field = $adjustment->formulaComponent === 'forecast_revenue' ? 'forecast_revenue' : 'manual_adjustments';
            $buckets[$key][$field] = $this->money((float) $buckets[$key][$field] + $adjustment->amount);
            $this->rememberStrings($buckets[$key]['source_types'], ['manual_adjustment']);
            $this->rememberStrings($buckets[$key]['risk_flags'], ['manual_adjustment']);
            $buckets[$key]['quality_status'] = $this->worstQuality((string) $buckets[$key]['quality_status'], 'attention');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function rows(WipForecastReportFilters $filters, array $buckets, WipForecastDimensions $dimensions): array
    {
        $rows = [];

        foreach ($buckets as $bucket) {
            $group = $bucket['group'];
            $currency = $this->groupedCurrency($group, $bucket['currencies']);
            $bac = (float) $bucket['bac'];
            $ev = $this->money((float) $bucket['ev']);
            $pv = $this->money((float) $bucket['pv']);
            $ac = $this->money((float) $bucket['ac']);
            $manualAdjustments = $this->money((float) $bucket['manual_adjustments']);
            $wip = $this->money(max($ev - (float) $bucket['approved_act_value'], 0.0));
            $ctc = $this->money(max($bac - $ev, 0.0));
            $cpi = $this->ratio($ev, $ac);
            $spi = $this->ratio($ev, $pv);
            $bottomUpEtc = (float) $bucket['bottom_up_etc'];
            $etc = $this->money($bottomUpEtc > 0.0 ? $bottomUpEtc : ($cpi !== null && $cpi > 0.0 ? $ctc / $cpi : $ctc));
            $ftc = $this->money($etc + $manualAdjustments);
            $eac = $this->money($ac + $ftc);
            $forecastRevenue = $this->money(max((float) $bucket['forecast_revenue'], (float) $bucket['approved_act_value'] + $wip));
            $margin = $this->money($forecastRevenue - $eac);
            $projectId = $this->singleGroupedId($filters, $group, WipForecastReportFilters::GROUP_PROJECT, $bucket['project_ids']);
            $stageId = $this->singleGroupedId($filters, $group, WipForecastReportFilters::GROUP_STAGE, $bucket['stage_ids']);
            $contractId = $this->singleGroupedId($filters, $group, WipForecastReportFilters::GROUP_CONTRACT, $bucket['contract_ids']);
            $estimateItemId = $this->singleGroupedId($filters, $group, WipForecastReportFilters::GROUP_ESTIMATE_ITEM, $bucket['estimate_item_ids']);

            $rows[] = [
                'group' => $group,
                'project' => $dimensions->project($projectId),
                'stage' => $dimensions->stage($stageId),
                'contract' => $dimensions->contract($contractId),
                'estimate_item' => $dimensions->estimateItem($estimateItemId),
                'currency' => $currency,
                'metrics' => [
                    'bac' => $this->money($bac),
                    'percent_complete' => $this->percent($ev, $bac),
                    'ev' => $ev,
                    'pv' => $pv,
                    'ac' => $ac,
                    'wip' => $wip,
                    'wip_total' => $wip,
                    'ctc' => $ctc,
                    'etc' => $etc,
                    'ftc' => $ftc,
                    'eac' => $eac,
                    'forecast_revenue' => $forecastRevenue,
                    'forecast_revenue_at_completion' => $forecastRevenue,
                    'forecast_gross_margin' => $margin,
                    'forecast_margin_percent' => $this->percent($margin, $forecastRevenue),
                    'cpi' => $cpi,
                    'spi' => $spi,
                    'approved_act_value' => $this->money((float) $bucket['approved_act_value']),
                    'cash_only_payments_excluded' => $this->money((float) $bucket['cash_only_payments_excluded']),
                    'manual_adjustments' => $manualAdjustments,
                ],
                'comparison' => $bucket['comparison'],
                'source_types' => array_keys($bucket['source_types']),
                'source_row_refs' => array_slice($bucket['source_row_refs'], 0, 50),
                'problem_flags' => array_keys($bucket['problem_flags']),
                'risk_flags' => array_keys($bucket['risk_flags']),
                'quality_status' => $bucket['quality_status'],
                'source_rows_count' => (int) $bucket['source_rows_count'],
                'drill_down_key' => WipForecastDrillDownKey::encode($filters->groupBy, $group),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [
                $left['currency'],
                (string) ($left['group'][WipForecastReportFilters::GROUP_PERIOD] ?? ''),
                (string) ($left['project']['name'] ?? ''),
                (string) ($left['contract']['number'] ?? ''),
            ] <=> [
                $right['currency'],
                (string) ($right['group'][WipForecastReportFilters::GROUP_PERIOD] ?? ''),
                (string) ($right['project']['name'] ?? ''),
                (string) ($right['contract']['number'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function summary(array $rows): array
    {
        $totals = [];
        $problemFlags = [];
        $riskFlags = [];
        $qualityStatus = 'actual';

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] ??= [
                'currency' => $currency,
                'bac' => 0.0,
                'ev' => 0.0,
                'pv' => 0.0,
                'ac' => 0.0,
                'wip' => 0.0,
                'wip_total' => 0.0,
                'ctc' => 0.0,
                'etc' => 0.0,
                'ftc' => 0.0,
                'eac' => 0.0,
                'forecast_revenue' => 0.0,
                'forecast_revenue_at_completion' => 0.0,
                'forecast_gross_margin' => 0.0,
                'cash_only_payments_excluded' => 0.0,
            ];

            foreach (array_keys($totals[$currency]) as $field) {
                if ($field === 'currency') {
                    continue;
                }

                $totals[$currency][$field] = $this->money(
                    (float) $totals[$currency][$field] + (float) ($row['metrics'][$field] ?? 0)
                );
            }

            $this->rememberStrings($problemFlags, $row['problem_flags']);
            $this->rememberStrings($riskFlags, $row['risk_flags']);
            $qualityStatus = $this->worstQuality($qualityStatus, (string) $row['quality_status']);
        }

        $totals = array_map(function (array $total): array {
            $total['percent_complete'] = $this->percent((float) $total['ev'], (float) $total['bac']);
            $total['forecast_margin_percent'] = $this->percent(
                (float) $total['forecast_gross_margin'],
                (float) $total['forecast_revenue'],
            );

            return $total;
        }, array_values($totals));

        return [
            'rows_count' => count($rows),
            'currencies' => array_map(static fn (array $total): string => (string) $total['currency'], $totals),
            'totals_by_currency' => $totals,
            'quality_status' => $qualityStatus,
            'has_forecast' => count($rows) > 0,
            'problem_flags' => array_keys($problemFlags),
            'risk_flags' => array_keys($riskFlags),
        ];
    }

    private function emptyBucket(array $group): array
    {
        return [
            'group' => $group,
            'bac' => 0.0,
            'pv' => 0.0,
            'ev' => 0.0,
            'approved_act_value' => 0.0,
            'ac' => 0.0,
            'cash_only_payments_excluded' => 0.0,
            'bottom_up_etc' => 0.0,
            'manual_adjustments' => 0.0,
            'forecast_revenue' => 0.0,
            'currencies' => [],
            'project_ids' => [],
            'stage_ids' => [],
            'contract_ids' => [],
            'estimate_item_ids' => [],
            'source_types' => [],
            'problem_flags' => [],
            'risk_flags' => [],
            'quality_status' => 'actual',
            'source_rows_count' => 0,
            'source_row_refs' => [],
            'comparison' => [
                'active_forecast' => null,
                'previous_forecast' => null,
                'approved_budget' => null,
            ],
        ];
    }

    /**
     * @param list<string> $groupBy
     * @return array<string, mixed>
     */
    private function groupParts(array $groupBy, WipForecastSourceAggregate $aggregate): array
    {
        $parts = [];

        foreach ($groupBy as $group) {
            $parts[$group] = match ($group) {
                WipForecastReportFilters::GROUP_PROJECT => $aggregate->projectId,
                WipForecastReportFilters::GROUP_STAGE => $aggregate->stageId,
                WipForecastReportFilters::GROUP_CONTRACT => $aggregate->contractId,
                WipForecastReportFilters::GROUP_ESTIMATE_ITEM => $aggregate->estimateItemId,
                WipForecastReportFilters::GROUP_PERIOD => $aggregate->periodMonth !== null ? mb_substr($aggregate->periodMonth, 0, 7) : null,
                WipForecastReportFilters::GROUP_CURRENCY => $aggregate->currency,
                default => null,
            };
        }

        return $parts;
    }

    private function singleGroupedId(WipForecastReportFilters $filters, array $groupParts, string $group, array $ids): ?int
    {
        if (in_array($group, $filters->groupBy, true)) {
            $value = $groupParts[$group] ?? null;

            return is_numeric($value) ? (int) $value : null;
        }

        $filterValue = match ($group) {
            WipForecastReportFilters::GROUP_PROJECT => $filters->projectId,
            WipForecastReportFilters::GROUP_STAGE => $filters->stageId,
            WipForecastReportFilters::GROUP_CONTRACT => $filters->contractId,
            WipForecastReportFilters::GROUP_ESTIMATE_ITEM => $filters->estimateItemId,
            default => null,
        };

        if ($filterValue !== null) {
            return $filterValue;
        }

        return count($ids) === 1 ? (int) array_key_first($ids) : null;
    }

    private function groupedCurrency(array $group, array $currencies): string
    {
        if (isset($group[WipForecastReportFilters::GROUP_CURRENCY]) && is_string($group[WipForecastReportFilters::GROUP_CURRENCY])) {
            return mb_strtoupper($group[WipForecastReportFilters::GROUP_CURRENCY]);
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : 'MIXED';
    }

    private function actions(?array $forecastVersion, array $permissions): array
    {
        $status = is_array($forecastVersion) ? (string) ($forecastVersion['status'] ?? '') : '';

        return [
            'can_create_version' => ($permissions['create_version'] ?? false) === true,
            'can_update_version' => ($permissions['update_version'] ?? false) === true && in_array($status, ['', 'editing'], true),
            'can_submit_version' => ($permissions['submit_version'] ?? false) === true && $status === 'editing',
            'can_approve_version' => ($permissions['approve_version'] ?? false) === true && $status === 'submitted',
            'can_activate_version' => ($permissions['activate_version'] ?? false) === true && $status === 'approved',
            'can_manage_adjustments' => ($permissions['manage_adjustments'] ?? false) === true && in_array($status, ['editing', 'approved', 'active'], true),
            'can_export' => ($permissions['export'] ?? false) === true,
            'can_view_audit' => ($permissions['view_audit'] ?? false) === true,
            'locked_reason' => $status === 'active' ? trans_message('budgeting.wip_forecast.actions.active_locked') : null,
        ];
    }

    private function freshness(array $sourceCoverage, array $meta): array
    {
        $status = 'actual';

        foreach ($sourceCoverage as $source) {
            if (($source['available'] ?? true) === false) {
                $status = 'partial';
                break;
            }

            if (($source['freshness_status'] ?? 'actual') === 'stale') {
                $status = 'stale';
            }
        }

        return [
            'status' => $status,
            'generated_at' => $meta['generated_at'] ?? null,
            'as_of_date' => $meta['as_of_date'] ?? null,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function formulas(): array
    {
        return [
            ['key' => 'bac', 'label' => 'Базовая стоимость', 'expression' => 'Утвержденная базовая стоимость работ'],
            ['key' => 'percent_complete', 'label' => 'Готовность', 'expression' => 'Освоенный объем / базовая стоимость'],
            ['key' => 'ev', 'label' => 'Освоенный объем', 'expression' => 'Базовая стоимость * готовность'],
            ['key' => 'pv', 'label' => 'Плановый объем', 'expression' => 'Плановая стоимость к дате прогноза'],
            ['key' => 'ac', 'label' => 'Фактические начисленные затраты', 'expression' => 'Управленческие начисленные затраты без денежных платежей, не подтвержденных начислениями'],
            ['key' => 'wip_total', 'label' => 'Незакрытый выполненный объем', 'expression' => 'максимум(освоенный объем - утвержденные акты заказчика, 0)'],
            ['key' => 'ctc', 'label' => 'Остаток базовой стоимости', 'expression' => 'максимум(базовая стоимость - освоенный объем, 0)'],
            ['key' => 'etc', 'label' => 'Системная оценка остатка', 'expression' => 'Детальная оценка остатка по строкам или остаток базовой стоимости / индекс выполнения бюджета'],
            ['key' => 'ftc', 'label' => 'Затраты до завершения', 'expression' => 'Системная оценка остатка + согласованные ручные корректировки'],
            ['key' => 'eac', 'label' => 'Оценка затрат при завершении', 'expression' => 'Фактические начисленные затраты + затраты до завершения'],
            ['key' => 'forecast_gross_margin', 'label' => 'Прогноз маржи', 'expression' => 'Прогнозная выручка - оценка затрат при завершении'],
        ];
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

    private function worstQuality(string $left, string $right): string
    {
        $rank = [
            'actual' => 0,
            'attention' => 1,
            'stale' => 2,
            'partial' => 3,
            'unavailable' => 4,
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

    private function ratio(float $numerator, float $denominator): ?float
    {
        if (abs($denominator) < 0.01) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }
}
