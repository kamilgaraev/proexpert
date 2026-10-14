<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

/** Legal classifications need measured time plus independently registered paper grounds. */
final class TimekeepingLaborReview
{
    public function apply(array &$days, array $relations, array $cells, array $rules, int $employeeId, string $month): void
    {
        $byDate = [];
        $annual = null;
        $baseline = [];
        foreach ($cells as $cell) {
            $review = $cell['review'] ?? [];
            $byDate[$cell['work_date']] = $review;
            if (isset($review['annual_minutes_before_month'], $review['annual_baseline_document_id'])) {
                $baseline[] = (int) $review['annual_minutes_before_month'];
            }
        }
        if (count(array_unique($baseline)) === 1) {
            $annual = $baseline[0];
        }
        $accounting = array_values(array_unique(array_filter(array_column($relations, 'summed_accounting_period'))));
        $summed = $accounting[0] ?? null;
        $monthEnd = (new \DateTimeImmutable($month.'-01'))->modify('last day of this month');
        $ended = $monthEnd < new \DateTimeImmutable('today');
        $accountingEnded = $ended && ($summed === 'month' || ($summed === 'quarter' && (int) $monthEnd->format('n') % 3 === 0) || ($summed === 'year' && $monthEnd->format('n') === '12'));
        foreach ($days as &$day) {
            $day['overtime_minutes'] = 0;
        } unset($day);
        if ($summed !== null) {
            if (! $accountingEnded) {
                foreach ($days as &$day) {
                    if ($day['fact_minutes'] > 0) {
                        $day['overtime_pending_accounting_period'] = true;
                        $this->issue($day, 'overtime_pending_accounting_period', $employeeId, 'warning');
                    }
                } unset($day);
            } else {
                $reviewed = null;
                foreach ($byDate as $review) {
                    if (isset($review['accounting_period_review_document_id'], $review['accounting_period_overtime_minutes'])) {
                        $reviewed = (float) $review['accounting_period_overtime_minutes'];
                    }
                }
                $expectedMonth = max(0, array_sum(array_column($days, 'fact_microseconds')) / 60000000 - array_sum(array_column($days, 'weekend_microseconds')) / 60000000 - array_sum(array_column($days, 'norm_minutes')));
                if ($reviewed === null || ($summed === 'month' && abs($reviewed - $expectedMonth) > 0.0000001)) {
                    foreach ($days as &$day) {
                        if ($day['fact_minutes'] > 0) {
                            $this->issue($day, 'accounting_period_reconciliation_required', $employeeId);
                        }
                    } unset($day);
                } else {
                    $allocatedTotal = 0;
                    foreach ($days as $date => &$day) {
                        $allocated = $byDate[$date]['overtime_minutes'] ?? 0;
                        if ($allocated < 0 || $allocated > max(0, $day['fact_minutes'] - $day['weekend_minutes'])) {
                            $this->issue($day, 'accounting_period_allocation_required', $employeeId);

                            continue;
                        }
                        $day['overtime_minutes'] = $allocated;
                        $allocatedTotal += $allocated;
                    } unset($day);
                    if (abs($allocatedTotal - $reviewed) > 0.0000001) {
                        $last = array_key_last($days);
                        $this->issue($days[$last], 'accounting_period_allocation_required', $employeeId);
                    }
                }
            }
        } else {
            foreach ($days as $date => &$day) {
                if (($relations[$date]['relation_type'] ?? null) !== 'employment') {
                    continue;
                }
                $day['overtime_minutes'] = max(0, $day['fact_minutes'] - max($day['planned_minutes'], $day['norm_minutes']) - $day['weekend_minutes']);
            } unset($day);
        }
        $previousOvertime = 0;
        foreach ($days as $date => &$day) {
            $relation = $relations[$date] ?? [];
            if (($relation['relation_type'] ?? null) !== 'employment') {
                continue;
            }
            $review = $byDate[$date] ?? [];
            if ($day['night_minutes'] > 0) {
                $day['night_payment_minimum_multiplier'] = $rules['night_minimum_multiplier'];
            }
            if ($day['weekend_minutes'] > 0 && ! isset($review['weekend_basis_document_id'])) {
                $this->issue($day, 'weekend_consent_compensation_review_required', $employeeId);
            }
            $overtime = $day['overtime_minutes'];
            if ($overtime > 0) {
                if (! isset($review['overtime_consent_document_id'], $review['overtime_order_document_id'])) {
                    $this->issue($day, 'overtime_consent_basis_review_required', $employeeId);
                }
                if ($date < $rules['overtime_effective_from']) {
                    if ($previousOvertime + $overtime > $rules['overtime_daily_minutes']) {
                        $this->issue($day, 'overtime_two_consecutive_days_limit_exceeded', $employeeId);
                    }
                } elseif ($overtime > $rules['overtime_daily_minutes']) {
                    $this->issue($day, 'overtime_daily_limit_exceeded', $employeeId);
                }
                if ($annual === null) {
                    $this->issue($day, 'annual_overtime_reconciliation_required', $employeeId);
                } else {
                    $before = $annual;
                    $annual += $overtime;
                    $limit = $date >= $rules['overtime_effective_from'] && isset($review['collective_agreement_document_id']) ? $rules['overtime_annual_agreement_minutes'] : $rules['overtime_annual_default_minutes'];
                    if (in_array($relation['metadata']['sout_class'] ?? null, ['3.3', '3.4'], true) || ! empty($relation['metadata']['public_internal_secondary'])) {
                        $limit = $rules['overtime_annual_default_minutes'];
                    }
                    if ($annual > $limit) {
                        $this->issue($day, 'overtime_annual_limit_exceeded', $employeeId);
                    }
                    $day['overtime_annual_minutes'] = $annual;
                    $ordinary = $date >= $rules['overtime_effective_from'] ? min($overtime, max(0, $rules['overtime_annual_default_minutes'] - $before)) : $overtime;
                    $first = min(120, $ordinary);
                    $day['overtime_payment_bands'] = array_values(array_filter([['minutes' => $first, 'minimum_multiplier' => 1.5], ['minutes' => $overtime - $first, 'minimum_multiplier' => 2.0]], static fn (array $band): bool => $band['minutes'] > 0));
                }
                if (! empty($rules['legal_review_required'])) {
                    $this->issue($day, 'overtime_legal_rule_review_required', $employeeId);
                }
            }
            $previousOvertime = $overtime;
        } unset($day);
    }

    private function issue(array &$day, string $code, int $employeeId, string $severity = 'blocking'): void
    {
        $day['issues'][] = ['code' => $code, 'severity' => $severity, 'employee_id' => $employeeId, 'work_date' => $day['work_date']];
    }
}
