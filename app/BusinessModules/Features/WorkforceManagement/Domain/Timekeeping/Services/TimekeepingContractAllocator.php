<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;

/** Allocation is explicit whenever more than one relation can own a measured interval. */
final class TimekeepingContractAllocator
{
    public function allocate(array $employees): array
    {
        $result = [];
        foreach ($employees as $employee) {
            $groups = [];
            foreach ($employee['relations'] ?? [] as $relation) {
                $key = (string) ($relation['contract_number'] ?? $relation['id']);
                $groups[$key][] = $relation;
            }
            if ($groups === []) {
                $result[] = $employee;

                continue;
            }
            $rows = [];
            foreach ($groups as $key => $relations) {
                usort($relations, static fn (array $a, array $b): int => ($a['revision'] ?? 1) <=> ($b['revision'] ?? 1));
                $latest = $relations[array_key_last($relations)];
                $rows[$key] = array_merge($employee, ['employment_contract_id' => (int) $latest['id'], 'contract_number' => $latest['contract_number'] ?? (string) $latest['id'], 'relations' => $relations, 'facts' => [], 'plans' => [], 'cells' => [], 'allocation_issues' => []]);
            }
            $unallocated = array_merge($employee, ['employment_contract_id' => null, 'contract_number' => null, 'relations' => [], 'facts' => [], 'plans' => [], 'cells' => [], 'absences' => [], 'unallocated' => true]);
            foreach (['facts', 'plans', 'cells'] as $collection) {
                foreach ($employee[$collection] ?? [] as $item) {
                    $owner = $this->owner($item, $groups);
                    if ($owner !== null) {
                        $rows[$owner][$collection][] = $item;
                    } elseif ($collection === 'facts' && array_filter($employee['cells'] ?? [], fn (array $cell): bool => $cell['work_date'] === ($item['work_date'] ?? null) && $this->owner($cell, $groups) !== null) !== []) {
                        // The reasoned, contract-scoped correction supersedes ambiguous facts;
                        // their original references remain in append-only correction lineage.
                        continue;
                    } elseif ($collection === 'plans') {
                        foreach ($rows as &$row) {
                            $row['allocation_issues'][] = ['work_date' => $item['work_date'], 'code' => 'schedule_contract_allocation_required'];
                        } unset($row);
                    } else {
                        $unallocated[$collection][] = $item;
                    }
                }
            }
            // Detect cross-contract physical overlap without deleting the recorded fact.
            $intervals = [];
            foreach ($rows as $key => &$row) {
                foreach ($row['facts'] as $factIndex => $fact) {
                    $fact = (new TimekeepingFactTimezone)->normalize($fact, $row['relations']);
                    try {
                        $interval = WorkTimeInterval::fromInput($fact);
                        if ($interval->timezoneRequiresReconciliation) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                    foreach ($intervals as $previous) {
                        if ($previous['owner'] !== $key && $interval->overlaps($previous['interval'])) {
                            $row['facts'][$factIndex]['allocation_reconciliation_required'] = true;
                            $row['facts'][$factIndex]['net_microseconds'] = $interval->netMicroseconds();
                            $row['facts'][$factIndex]['net_minutes'] = $interval->netMinutes();
                            $row['allocation_issues'][] = ['work_date' => $fact['work_date'], 'code' => 'cross_contract_interval_overlap'];
                            $rows[$previous['owner']]['allocation_issues'][] = ['work_date' => $previous['date'], 'code' => 'cross_contract_interval_overlap'];
                        }
                    }
                    $intervals[] = ['owner' => $key, 'date' => $fact['work_date'], 'interval' => $interval];
                }
            } unset($row);
            foreach ($rows as $row) {
                $result[] = $row;
            }
            if ($unallocated['facts'] !== [] || $unallocated['cells'] !== []) {
                $result[] = $unallocated;
            }
        }

        return $result;
    }

    private function owner(array $item, array $groups): ?string
    {
        $explicit = isset($item['employment_contract_id']) ? (int) $item['employment_contract_id'] : null;
        $date = (string) ($item['work_date'] ?? '');
        $owners = [];
        foreach ($groups as $key => $relations) {
            foreach ($relations as $relation) {
                if ($explicit !== null) {
                    if ((int) $relation['id'] === $explicit) {
                        return (string) $key;
                    }
                } elseif (($relation['relation_type'] ?? null) === 'employment') {
                    $localDate = $date;
                    if (! empty($relation['timezone']) && (isset($item['start_at']) || isset($item['start_time']))) {
                        $localDate = (string) ((new TimekeepingFactTimezone)->forRelation($item, $relation)['work_date'] ?? $date);
                    }
                    if ($relation['start_date'] <= $localDate && (empty($relation['end_date']) || $relation['end_date'] >= $localDate)) {
                        $owners[$key] = true;
                    }
                }
            }
        }

        return count($owners) === 1 ? (string) array_key_first($owners) : null;
    }
}
