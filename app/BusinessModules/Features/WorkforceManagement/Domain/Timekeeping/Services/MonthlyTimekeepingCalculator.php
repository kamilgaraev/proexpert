<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;

/** Pure calculation: scheduling and presence never manufacture measured labour facts. */
final class MonthlyTimekeepingCalculator
{
    public function calculate(string $month, array $employees, array $rules): array
    {
        $first = new \DateTimeImmutable($month.'-01');
        if ($first->format('Y-m') !== $month) {
            throw new \InvalidArgumentException('month');
        }
        $last = $first->modify('last day of this month')->format('Y-m-d');
        $rows = [];
        $allIssues = [];
        $totals = $this->emptySummary();
        foreach ((new TimekeepingContractAllocator)->allocate($employees) as $employee) {
            $row = ['employee_id' => (int) $employee['id'], 'employment_contract_id' => $employee['employment_contract_id'] ?? null, 'contract_number' => $employee['contract_number'] ?? null, 'personnel_number' => $employee['personnel_number'], 'full_name' => $employee['full_name'], 'relation_type' => null, 'retention_class' => array_filter($employee['relations'] ?? [], static fn (array $r): bool => ! empty($r['metadata']['hazardous_work']) || in_array($r['metadata']['sout_class'] ?? null, ['3.1', '3.2', '3.3', '3.4', '4'], true)) !== [] ? 'hazardous' : 'ordinary', 'days' => [], 'summary' => $this->emptySummary(), 'issues' => []];
            $days = [];
            $dayRelations = [];
            for ($cursor = $first; $cursor->format('Y-m-d') <= $last; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                $days[$date] = ['work_date' => $date, 'norm_minutes' => 0, 'planned_minutes' => 0, 'fact_minutes' => 0, 'night_minutes' => 0, 'weekend_minutes' => 0, 'overtime_minutes' => 0, 'reported_unverified_minutes' => 0, 'fact_microseconds' => 0, 'night_microseconds' => 0, 'weekend_microseconds' => 0, 'reported_unverified_microseconds' => 0, 'absence_code' => null, 'sources' => [], 'intervals' => [], 'unresolved_facts' => [], 'issues' => []];
                $relations = array_values(array_filter($employee['relations'] ?? [], static fn (array $r): bool => $r['status'] === 'active' && $r['start_date'] <= $date && (empty($r['end_date']) || $r['end_date'] >= $date)));
                if (count($relations) !== 1) {
                    if (! empty($employee['unallocated'])) {
                        continue;
                    }
                    if (count($relations) === 0 && ($employee['relations'] ?? []) !== []) {
                        continue;
                    }
                    $this->issue($days[$date], 'relation_reconciliation_required', $employee['id']);

                    continue;
                }
                $relation = $relations[0];
                $dayRelations[$date] = $relation;
                $days[$date]['employment_contract_id'] = (int) $relation['id'];
                foreach ($employee['allocation_issues'] ?? [] as $allocationIssue) {
                    if ($allocationIssue['work_date'] === $date) {
                        $this->issue($days[$date], $allocationIssue['code'], $employee['id']);
                    }
                }
                $row['relation_type'] = $relation['relation_type'];
                $days[$date]['sources'][] = ['type' => 'employment_relation', 'id' => $relation['id'], 'revision' => $relation['revision'] ?? 1];
                if ($relation['relation_type'] === 'civil') {
                    continue;
                }
                if ($relation['relation_type'] !== 'employment' || empty($relation['timezone']) || empty($relation['employment_mode'])) {
                    $this->issue($days[$date], 'relation_reconciliation_required', $employee['id']);

                    continue;
                }
                $norm = TimekeepingRules::normMinutes($date, $relation, $rules);
                if ($norm === null) {
                    $this->issue($days[$date], 'calendar_missing', $employee['id']);
                }
                $days[$date]['norm_minutes'] = $norm ?? 0;
                $plans = array_values(array_filter($employee['plans'] ?? [], static fn (array $p): bool => $p['work_date'] === $date));
                if (count($plans) > 1) {
                    $this->issue($days[$date], 'schedule_overlap', $employee['id']);
                }
                if (count($plans) === 1) {
                    $days[$date]['planned_minutes'] = (int) $plans[0]['minutes'];
                    $days[$date]['sources'][] = $plans[0]['source'];
                }
                foreach ($employee['absences'] ?? [] as $absence) {
                    if ($absence['start_date'] <= $date && $absence['end_date'] >= $date) {
                        $days[$date]['sources'][] = ['type' => $absence['source_type'] ?? 'absence', 'id' => $absence['id'], 'revision' => $absence['revision'] ?? 1];
                        if ($absence['status'] !== 'approved') {
                            $this->issue($days[$date], ($absence['source_type'] ?? null) === 'business_trip' ? 'business_trip_not_approved' : 'absence_not_approved', $employee['id']);

                            continue;
                        }
                        if ($days[$date]['absence_code'] !== null) {
                            $this->issue($days[$date], 'absence_overlap', $employee['id']);
                        }
                        $days[$date]['absence_code'] = $absence['code'];
                        $days[$date]['absence_ground'] = ['basis_reference' => $absence['basis_reference'] ?? null, 'basis_document_id' => $absence['basis_document_id'] ?? null];
                        if (empty($absence['basis_reference']) && empty($absence['basis_document_id'])) {
                            $this->issue($days[$date], 'absence_basis_missing', $employee['id']);
                        }
                    }
                }
            }
            foreach (array_merge($employee['presence'] ?? [], $employee['presence_corrections'] ?? []) as $presence) {
                if (isset($days[$presence['work_date']])) {
                    $days[$presence['work_date']]['presence_status'] = $presence['status'];
                    $days[$presence['work_date']]['sources'][] = $presence['source'];
                }
            }
            $facts = $employee['facts'] ?? [];
            // Latest manual correction replaces all measured sources on its affected local dates.
            $replaced = [];
            foreach ($employee['cells'] ?? [] as $cell) {
                $replaced[$cell['work_date']] = true;
                foreach ($cell['intervals'] ?? [] as $input) {
                    try {
                        foreach (WorkTimeInterval::fromInput($input)->splitByLocalDate() as $date => $unused) {
                            $replaced[$date] = true;
                        }
                    } catch (\InvalidArgumentException) {
                    }
                }
            }
            $intervals = [];
            $seen = [];
            foreach ($facts as $fact) {
                $this->measure($fact, $days, $dayRelations, $rules, $employee['id'], $intervals, $seen, $replaced, $employee['relations'] ?? []);
            }
            foreach ($employee['cells'] ?? [] as $cell) {
                foreach ($cell['intervals'] ?? [] as $index => $input) {
                    $this->measure(array_merge($input, ['source_type' => 'manual_cell', 'source_id' => $cell['id'], 'interval_index' => $index, 'source_revision' => $cell['revision']]), $days, $dayRelations, $rules, $employee['id'], $intervals, $seen, [], $employee['relations'] ?? []);
                }
                if (isset($days[$cell['work_date']])) {
                    $days[$cell['work_date']]['sources'][] = ['type' => 'manual_cell', 'id' => $cell['id'], 'revision' => $cell['revision']];
                    $days[$cell['work_date']]['correction'] = ['reason_code' => $cell['reason_code'], 'basis_reference' => $cell['basis_reference'], 'basis_document_id' => $cell['basis_document_id'] ?? null, 'comment' => $cell['comment'] ?? null];
                }
            }
            (new TimekeepingLaborReview)->apply($days, $dayRelations, $employee['cells'] ?? [], $rules, $employee['id'], $month);
            $tripWorkReviews = [];
            foreach ($employee['cells'] ?? [] as $cell) {
                $tripWorkReviews[$cell['work_date']] = $cell['review']['business_trip_work_document_id']['id'] ?? null;
            }
            foreach ($days as $date => &$day) {
                $relation = $dayRelations[$date] ?? [];
                $trip = $day['absence_code'] === 'business_trip';
                $tripReviewed = $trip && ! empty($day['absence_ground']['basis_document_id'])
                    && (int) ($tripWorkReviews[$date] ?? 0) === (int) $day['absence_ground']['basis_document_id'];
                if (($relation['relation_type'] ?? null) === 'civil') {
                    $day['norm_minutes'] = 0;
                    $day['planned_minutes'] = 0;
                    if ($day['fact_minutes'] > 0) {
                        $this->issue($day, 'civil_not_normative_labor', $employee['id']);
                    }
                } elseif (($relation['relation_type'] ?? null) === 'employment') {
                    if ($day['absence_code'] !== null && ($day['presence_status'] ?? null) === 'confirmed' && ! $tripReviewed) {
                        $this->issue($day, 'presence_absence_review_required', $employee['id']);
                    }
                    if (in_array($day['presence_status'] ?? null, ['absent', 'not_at_work'], true) && $day['fact_minutes'] > 0) {
                        $this->issue($day, 'attendance_fact_conflict', $employee['id']);
                    }
                    if ($day['absence_code'] !== null && $day['fact_minutes'] > 0 && ! $tripReviewed) {
                        $this->issue($day, $trip ? 'business_trip_work_review_required' : 'absence_fact_conflict', $employee['id']);
                    }
                    if (max($day['norm_minutes'], $day['planned_minutes']) > 0 && $day['fact_minutes'] === 0 && $day['absence_code'] === null && $date < date('Y-m-d')) {
                        $this->issue($day, 'measured_fact_missing', $employee['id']);
                    }
                }
                foreach ($row['summary'] as $key => &$value) {
                    $value += $day[$key] ?? 0;
                } unset($value);
                $row['issues'] = array_merge($row['issues'], $day['issues']);
            } unset($day);
            foreach (['fact', 'night', 'weekend', 'reported_unverified'] as $metric) {
                $row['summary'][$metric.'_minutes'] = $row['summary'][$metric.'_microseconds'] / 60000000;
            }
            $row['days'] = array_values($days);
            foreach ($totals as $key => &$value) {
                $value += $row['summary'][$key];
            } unset($value);
            $allIssues = array_merge($allIssues, $row['issues']);
            $rows[] = $row;
        }
        foreach (['fact', 'night', 'weekend', 'reported_unverified'] as $metric) {
            $totals[$metric.'_minutes'] = $totals[$metric.'_microseconds'] / 60000000;
        }
        $totals['employee_count'] = count(array_unique(array_column($rows, 'employee_id')));
        $totals['blocking_issue_count'] = count(array_filter($allIssues, static fn (array $i): bool => $i['severity'] === 'blocking'));

        return ['month' => $month, 'rule_version' => $rules['version'], 'rows' => $rows, 'summary' => $totals, 'issues' => $allIssues];
    }

    private function measure(array $fact, array &$days, array $relations, array $rules, int $employeeId, array &$intervals, array &$seen, array $replaced, array $effectiveRelations): void
    {
        $fact = (new TimekeepingFactTimezone)->normalize($fact, $effectiveRelations);
        try {
            if (! empty($fact['allocation_reconciliation_required'])) {
                throw new \InvalidArgumentException('contract_overlap');
            }
            $interval = WorkTimeInterval::fromInput($fact);
            $segments = $interval->segments();
        } catch (\Throwable) {
            $date = substr((string) ($fact['work_date'] ?? ''), 0, 10);
            if (isset($days[$date]) && ! isset($replaced[$date])) {
                $this->issue($days[$date], 'interval_reconciliation_required', $employeeId);
                $reported = $fact['net_minutes'] ?? (isset($fact['hours_worked']) ? (float) $fact['hours_worked'] * 60 : null);
                if ($reported !== null && is_numeric($reported) && is_finite((float) $reported) && $reported >= 0) {
                    $days[$date]['reported_unverified_microseconds'] += (int) ($fact['net_microseconds'] ?? round((float) $reported * 60000000));
                    $days[$date]['reported_unverified_minutes'] = $days[$date]['reported_unverified_microseconds'] / 60000000;
                }
                $days[$date]['unresolved_facts'][] = $fact;
            }

            return;
        }
        $identity = ($fact['source_type'] ?? 'fact').':'.($fact['source_id'] ?? 0).':'.($fact['interval_index'] ?? 0);
        if (isset($seen[$identity])) {
            return;
        } $seen[$identity] = true;
        foreach ($intervals as $previous) {
            if ($interval->overlaps($previous)) {
                foreach ($segments as $segment) {
                    if (isset($days[$segment['date']]) && ! isset($replaced[$segment['date']])) {
                        $this->issue($days[$segment['date']], 'measured_interval_overlap', $employeeId);
                        $days[$segment['date']]['reported_unverified_microseconds'] += $segment['microseconds'];
                        $days[$segment['date']]['reported_unverified_minutes'] = $days[$segment['date']]['reported_unverified_microseconds'] / 60000000;
                        $days[$segment['date']]['unresolved_facts'][] = $fact;
                    }
                }

                return;
            }
        }
        if (array_filter($segments, static fn (array $segment): bool => isset($days[$segment['date']]) && ! isset($replaced[$segment['date']])) === []) {
            return;
        }
        $intervals[] = $interval;
        foreach ($segments as $segment) {
            $date = $segment['date'];
            if (! isset($days[$date]) || isset($replaced[$date])) {
                continue;
            }
            $relation = $relations[$date] ?? [];
            if (($relation['relation_type'] ?? null) !== 'employment') {
                $this->issue($days[$date], 'fact_without_employment_relation', $employeeId);
            }
            if (($relation['timezone'] ?? null) !== $interval->timezone) {
                $this->issue($days[$date], 'fact_timezone_mismatch', $employeeId);
            }
            $days[$date]['fact_microseconds'] += $segment['microseconds'];
            $days[$date]['fact_minutes'] = $days[$date]['fact_microseconds'] / 60000000;
            if ($segment['night']) {
                $days[$date]['night_microseconds'] += $segment['microseconds'];
                $days[$date]['night_minutes'] = $days[$date]['night_microseconds'] / 60000000;
            }
            if (TimekeepingRules::holiday($date, $relation, $rules) || (TimekeepingRules::nonWorking($date, $relation, $rules) && $days[$date]['planned_minutes'] === 0)) {
                $days[$date]['weekend_microseconds'] += $segment['microseconds'];
                $days[$date]['weekend_minutes'] = $days[$date]['weekend_microseconds'] / 60000000;
            }
            $source = ['type' => $fact['source_type'] ?? 'fact', 'id' => $fact['source_id'] ?? 0, 'revision' => $fact['source_revision'] ?? 1];
            if (! in_array($source, $days[$date]['sources'], true)) {
                $days[$date]['sources'][] = $source;
            }
            $detail = array_merge($interval->persistence(), ['source_type' => $source['type'], 'source_id' => $source['id']]);
            if (isset($fact['source_work_date'])) {
                $detail['source_work_date'] = $fact['source_work_date'];
            }
            if (! in_array($detail, $days[$date]['intervals'], true)) {
                $days[$date]['intervals'][] = $detail;
            }
        }
    }

    private function emptySummary(): array
    {
        return ['norm_minutes' => 0, 'planned_minutes' => 0, 'fact_minutes' => 0, 'night_minutes' => 0, 'weekend_minutes' => 0, 'overtime_minutes' => 0, 'reported_unverified_minutes' => 0, 'fact_microseconds' => 0, 'night_microseconds' => 0, 'weekend_microseconds' => 0, 'reported_unverified_microseconds' => 0];
    }

    private function issue(array &$day, string $code, int $employeeId, string $severity = 'blocking'): void
    {
        $issue = ['code' => $code, 'severity' => $severity, 'employee_id' => $employeeId, 'work_date' => $day['work_date']];
        if (! in_array($issue, $day['issues'], true)) {
            $day['issues'][] = $issue;
        }
    }
}
