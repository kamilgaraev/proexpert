<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityAffectedCohortPlanner
{
    public function plan(
        WorkforceCapacityCaptureCommand $command,
        iterable $relatedAssignments,
        string $businessDate,
    ): iterable {
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($today === false || $today->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('workforce_capacity_business_date_invalid');
        }

        $rows = [];
        foreach ($relatedAssignments as $assignment) {
            $rows[] = (array) $assignment;
        }
        foreach ([$command->oldState, $command->newState] as $state) {
            if (is_array($state) && isset($state['staff_unit_id'])) {
                $rows[] = $state;
            }
        }

        $keys = [];
        foreach ($rows as $row) {
            if ((int) ($row['organization_id'] ?? 0) !== $command->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_planner_organization_mismatch');
            }
            $staffUnitId = (int) ($row['staff_unit_id'] ?? 0);
            if ($staffUnitId < 1) {
                throw new InvalidArgumentException('workforce_capacity_planner_staff_unit_missing');
            }
            $projectId = ($row['project_id'] ?? null) === null ? null : (int) $row['project_id'];
            if ($projectId !== null && $projectId < 1) {
                throw new InvalidArgumentException('workforce_capacity_planner_project_invalid');
            }

            foreach ($this->months($row, $today) as $month) {
                $asOf = $month->format('Y-m') === $today->format('Y-m')
                    ? $businessDate
                    : $month->modify('last day of this month')->format('Y-m-d');
                foreach (array_values(array_unique([null, $projectId], SORT_REGULAR)) as $bucketProjectId) {
                    $key = new WorkforceCapacityCohortKey(
                        $command->organizationId,
                        $asOf,
                        $month->format('Y-m-01'),
                        $staffUnitId,
                        $bucketProjectId,
                    );
                    $keys[$key->sortIdentity()] = $key;
                }
            }
        }

        if ($keys === []) {
            foreach ([$command->oldState, $command->newState] as $state) {
                if (! is_array($state)) {
                    continue;
                }
                $staffUnitId = $command->sourceType === 'staff_unit'
                    ? (int) ($state['id'] ?? 0)
                    : (int) ($state['staff_unit_id'] ?? 0);
                if ($staffUnitId < 1) {
                    continue;
                }
                $key = new WorkforceCapacityCohortKey(
                    $command->organizationId,
                    $businessDate,
                    $today->format('Y-m-01'),
                    $staffUnitId,
                    null,
                );
                $keys[$key->sortIdentity()] = $key;
            }
        }

        ksort($keys, SORT_STRING);
        yield from array_values($keys);
    }

    private function months(array $row, DateTimeImmutable $today): iterable
    {
        $fromText = (string) ($row['valid_from'] ?? $today->format('Y-m-d'));
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromText);
        if ($from === false || $from->format('Y-m-d') !== $fromText) {
            throw new InvalidArgumentException('workforce_capacity_planner_range_invalid');
        }
        $start = $from > $today ? $from->modify('first day of this month') : $today->modify('first day of this month');
        $toText = $row['valid_to'] ?? null;
        if ($toText === null) {
            yield $start;

            return;
        }
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $toText);
        if ($to === false || $to->format('Y-m-d') !== (string) $toText || $to < $from) {
            throw new InvalidArgumentException('workforce_capacity_planner_range_invalid');
        }
        if ($to < $today) {
            yield $today->modify('first day of this month');

            return;
        }
        $endExclusive = $to->modify('first day of next month');
        yield from new DatePeriod($start, new DateInterval('P1M'), $endExclusive);
    }
}
