<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityRangeDescriptor;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityAffectedCohortPlanner
{
    public function describe(
        WorkforceCapacityCaptureCommand $command,
        iterable $relatedAssignments,
        string $businessDate,
    ): array {
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($today === false || $today->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('workforce_capacity_business_date_invalid');
        }
        $assignments = [];
        foreach ($relatedAssignments as $assignment) {
            $assignments[] = (array) $assignment;
        }
        $rows = $this->affectedRanges($command, $assignments, $businessDate);
        if ($rows === []) {
            foreach ([$command->oldState, $command->newState] as $state) {
                if (! is_array($state)) {
                    continue;
                }
                $staffUnitId = $command->sourceType === 'staff_unit'
                    ? (int) ($state['id'] ?? 0)
                    : (int) ($state['staff_unit_id'] ?? 0);
                if ($staffUnitId > 0) {
                    $rows[] = [
                        'organization_id' => $command->organizationId,
                        'staff_unit_id' => $staffUnitId,
                        'project_id' => null,
                        'valid_from' => $businessDate,
                        'valid_to' => $businessDate,
                    ];
                }
            }
        }

        $descriptors = [];
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
            [$fromMonth, $throughMonth] = $this->monthBounds(
                $row,
                $today,
                $command->sourceType === 'capture_request',
            );
            foreach (array_values(array_unique([null, $projectId], SORT_REGULAR)) as $bucketProjectId) {
                $descriptor = new WorkforceCapacityRangeDescriptor(
                    $command->organizationId,
                    $staffUnitId,
                    $bucketProjectId,
                    $fromMonth,
                    $throughMonth,
                );
                $identity = implode(':', [
                    $staffUnitId,
                    $bucketProjectId ?? 'null',
                    $fromMonth,
                    $throughMonth,
                ]);
                $descriptors[$identity] = $descriptor;
            }
        }
        uasort($descriptors, static function (WorkforceCapacityRangeDescriptor $left, WorkforceCapacityRangeDescriptor $right): int {
            return [
                $left->fromMonth,
                $left->staffUnitId,
                $left->projectId === null ? 0 : 1,
                $left->projectId ?? 0,
                $left->throughMonth,
            ] <=> [
                $right->fromMonth,
                $right->staffUnitId,
                $right->projectId === null ? 0 : 1,
                $right->projectId ?? 0,
                $right->throughMonth,
            ];
        });

        return array_values($descriptors);
    }

    public function plan(
        WorkforceCapacityCaptureCommand $command,
        iterable $relatedAssignments,
        string $businessDate,
    ): iterable {
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($today === false || $today->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('workforce_capacity_business_date_invalid');
        }

        if ($command->sourceType === 'capture_request') {
            yield from $this->explicitCaptureKeys($command);

            return;
        }

        $keys = [];
        foreach ($this->describe($command, $relatedAssignments, $businessDate) as $descriptor) {
            $start = new DateTimeImmutable($descriptor->fromMonth);
            $endExclusive = (new DateTimeImmutable($descriptor->throughMonth))->add(new DateInterval('P1M'));
            foreach (new DatePeriod($start, new DateInterval('P1M'), $endExclusive) as $month) {
                $asOf = $month->format('Y-m') === $today->format('Y-m')
                    ? $businessDate
                    : $month->modify('last day of this month')->format('Y-m-d');
                $key = new WorkforceCapacityCohortKey(
                    $descriptor->organizationId,
                    $asOf,
                    $month->format('Y-m-01'),
                    $descriptor->staffUnitId,
                    $descriptor->projectId,
                );
                $keys[$key->sortIdentity()] = $key;
            }
        }

        ksort($keys, SORT_STRING);
        yield from array_values($keys);
    }

    private function affectedRanges(
        WorkforceCapacityCaptureCommand $command,
        array $assignments,
        string $businessDate,
    ): array {
        if ($command->sourceType === 'capture_request') {
            return $this->captureRequestRanges($command);
        }
        if (in_array($command->sourceType, ['absence', 'business_trip'], true)) {
            return $this->unavailabilityRanges($command, $assignments);
        }
        if ($command->sourceType === 'schedule_day') {
            return $this->scheduleDayRanges($command, $assignments);
        }

        $rows = $assignments;
        foreach ([$command->oldState, $command->newState] as $state) {
            if (! is_array($state)) {
                continue;
            }
            if ($command->sourceType === 'staff_unit') {
                $rows[] = [
                    ...$state,
                    'organization_id' => $command->organizationId,
                    'staff_unit_id' => (int) ($state['id'] ?? 0),
                    'project_id' => null,
                    'valid_from' => $state['valid_from'] ?? $businessDate,
                ];
            } elseif (isset($state['staff_unit_id'])) {
                $rows[] = $state;
            }
        }

        return $rows;
    }

    private function captureRequestRanges(WorkforceCapacityCaptureCommand $command): array
    {
        $rows = [];
        foreach ([$command->oldState, $command->newState] as $state) {
            if (! is_array($state)) {
                continue;
            }
            $monthStart = (string) ($state['month_start'] ?? '');
            $rows[] = [
                ...$state,
                'organization_id' => $command->organizationId,
                'valid_from' => $monthStart,
                'valid_to' => $monthStart,
            ];
        }

        return $rows;
    }

    private function unavailabilityRanges(WorkforceCapacityCaptureCommand $command, array $assignments): array
    {
        $rows = [];
        foreach ([$command->oldState, $command->newState] as $state) {
            if (! is_array($state) || ($state['status'] ?? null) !== 'approved') {
                continue;
            }
            $from = (string) ($state['start_date'] ?? '');
            $to = (string) ($state['end_date'] ?? '');
            foreach ($assignments as $assignment) {
                if ((int) ($assignment['employee_id'] ?? 0) !== (int) ($state['employee_id'] ?? 0)) {
                    continue;
                }
                $assignmentFrom = (string) ($assignment['valid_from'] ?? '');
                $assignmentTo = $assignment['valid_to'] ?? null;
                $intersectionFrom = max($from, $assignmentFrom);
                $intersectionTo = $assignmentTo === null ? $to : min($to, (string) $assignmentTo);
                if ($intersectionFrom === '' || $intersectionTo === '' || $intersectionFrom > $intersectionTo) {
                    continue;
                }
                $rows[] = [
                    ...$assignment,
                    'valid_from' => $intersectionFrom,
                    'valid_to' => $intersectionTo,
                ];
            }
        }

        return $rows;
    }

    private function scheduleDayRanges(WorkforceCapacityCaptureCommand $command, array $assignments): array
    {
        $rows = [];
        foreach ([$command->oldState, $command->newState] as $state) {
            if (! is_array($state)) {
                continue;
            }
            $scheduleId = (int) ($state['work_schedule_id'] ?? 0);
            $workDate = (string) ($state['work_date'] ?? '');
            foreach ($assignments as $assignment) {
                if ((int) ($assignment['work_schedule_id'] ?? 0) !== $scheduleId
                    || (string) ($assignment['valid_from'] ?? '') > $workDate
                    || (($assignment['valid_to'] ?? null) !== null && (string) $assignment['valid_to'] < $workDate)) {
                    continue;
                }
                $rows[] = [
                    ...$assignment,
                    'valid_from' => $workDate,
                    'valid_to' => $workDate,
                ];
            }
        }

        return $rows;
    }

    private function explicitCaptureKeys(WorkforceCapacityCaptureCommand $command): iterable
    {
        $keys = [];
        foreach ([$command->oldState, $command->newState] as $state) {
            if (! is_array($state)) {
                continue;
            }
            $key = new WorkforceCapacityCohortKey(
                $command->organizationId,
                (string) ($state['as_of_date'] ?? ''),
                (string) ($state['month_start'] ?? ''),
                (int) ($state['staff_unit_id'] ?? 0),
                ($state['project_id'] ?? null) === null ? null : (int) $state['project_id'],
            );
            $keys[$key->sortIdentity()] = $key;
        }
        ksort($keys, SORT_STRING);

        yield from array_values($keys);
    }

    private function monthBounds(array $row, DateTimeImmutable $today, bool $preserveHistorical = false): array
    {
        $fromText = (string) ($row['valid_from'] ?? $today->format('Y-m-d'));
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromText);
        if ($from === false || $from->format('Y-m-d') !== $fromText) {
            throw new InvalidArgumentException('workforce_capacity_planner_range_invalid');
        }
        $start = $preserveHistorical || $from > $today
            ? $from->modify('first day of this month')
            : $today->modify('first day of this month');
        $toText = $row['valid_to'] ?? null;
        if ($toText === null) {
            return [$start->format('Y-m-d'), $start->format('Y-m-d')];
        }
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $toText);
        if ($to === false || $to->format('Y-m-d') !== (string) $toText || $to < $from) {
            throw new InvalidArgumentException('workforce_capacity_planner_range_invalid');
        }
        $through = ! $preserveHistorical && $to < $today ? $today : $to;

        return [$start->format('Y-m-d'), $through->modify('first day of this month')->format('Y-m-d')];
    }
}
