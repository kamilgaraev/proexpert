<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class EloquentWorkforceCapacityCurrentSource implements WorkforceCapacityCurrentSource
{
    public function __construct(private WorkforceCapacityAffectedCohortPlanner $planner) {}

    public function affectedCohorts(WorkforceCapacityCaptureCommand $command, string $asOfDate): iterable
    {
        $assignments = $this->relatedAssignments($command);

        return $this->planner->plan($command, $assignments, $asOfDate);
    }

    public function readBatch(WorkforceCapacityCaptureCommand $command, array $keys): array
    {
        if ($keys === [] || count($keys) > 500) {
            throw new InvalidArgumentException('workforce_capacity_source_batch_size_invalid');
        }
        foreach ($keys as $key) {
            if (! $key instanceof WorkforceCapacityCohortKey || $key->organizationId !== $command->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_source_batch_identity_invalid');
            }
        }

        $staffUnitIds = array_values(array_unique(array_map(static fn (WorkforceCapacityCohortKey $key): int => $key->staffUnitId, $keys)));
        $dates = array_map(static fn (WorkforceCapacityCohortKey $key): string => $key->asOfDate, $keys);
        sort($dates, SORT_STRING);
        $minDate = $dates[0];
        $maxDate = $dates[array_key_last($dates)];
        $monthStarts = array_map(static fn (WorkforceCapacityCohortKey $key): string => $key->monthStart, $keys);
        sort($monthStarts, SORT_STRING);
        $minMonth = $monthStarts[0];
        $maxMonthEnd = (new \DateTimeImmutable($monthStarts[array_key_last($monthStarts)]))->modify('last day of this month')->format('Y-m-d');

        $staffUnits = DB::table('workforce_staff_units')
            ->where('organization_id', $command->organizationId)
            ->whereIn('id', $staffUnitIds)
            ->get([
                'id', 'organization_id', 'department_id', 'position_id', 'headcount', 'rate',
                'valid_from', 'valid_to', 'is_active', 'deleted_at',
            ])->map(fn ($row): array => (array) $row)->keyBy('id')->all();
        $assignments = DB::table('workforce_employee_assignments')
            ->where('organization_id', $command->organizationId)
            ->whereIn('staff_unit_id', $staffUnitIds)
            ->whereDate('valid_from', '<=', $maxDate)
            ->where(function ($query) use ($minDate): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $minDate);
            })
            ->orderBy('id')
            ->get([
                'id', 'organization_id', 'employee_id', 'staff_unit_id', 'department_id', 'position_id',
                'project_id', 'work_schedule_id', 'rate', 'valid_from', 'valid_to', 'status', 'deleted_at',
            ])->map(fn ($row): array => (array) $row)->all();
        $scheduleIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row['work_schedule_id'] === null ? null : (int) $row['work_schedule_id'],
            $assignments,
        ))));
        $employeeIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['employee_id'], $assignments)));

        $schedules = $scheduleIds === [] ? [] : DB::table('workforce_work_schedules')
            ->where('organization_id', $command->organizationId)
            ->whereIn('id', $scheduleIds)
            ->get(['id', 'organization_id', 'schedule_type', 'week_pattern', 'is_active', 'deleted_at'])
            ->map(fn ($row): array => (array) $row)->all();
        $scheduleDays = $scheduleIds === [] ? [] : DB::table('workforce_work_schedule_days')
            ->where('organization_id', $command->organizationId)
            ->whereIn('work_schedule_id', $scheduleIds)
            ->whereBetween('work_date', [$minMonth, $maxMonthEnd])
            ->orderBy('id')
            ->get(['id', 'organization_id', 'work_schedule_id', 'work_date', 'day_type', 'planned_hours'])
            ->map(fn ($row): array => (array) $row)->all();
        $absences = $employeeIds === [] ? [] : DB::table('workforce_absences as absence')
            ->join('workforce_absence_types as type', function ($join): void {
                $join->on('type.id', '=', 'absence.absence_type_id')
                    ->on('type.organization_id', '=', 'absence.organization_id');
            })
            ->where('absence.organization_id', $command->organizationId)
            ->whereIn('absence.employee_id', $employeeIds)
            ->whereDate('absence.start_date', '<=', $maxDate)
            ->whereDate('absence.end_date', '>=', $minDate)
            ->orderBy('absence.id')
            ->get([
                'absence.id', 'absence.organization_id', 'absence.employee_id', 'absence.absence_type_id',
                'absence.start_date', 'absence.end_date', 'absence.status', 'absence.deleted_at',
                'type.affects_payroll',
            ])->map(fn ($row): array => (array) $row)->all();
        $trips = $employeeIds === [] ? [] : DB::table('workforce_business_trips')
            ->where('organization_id', $command->organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $maxDate)
            ->whereDate('end_date', '>=', $minDate)
            ->orderBy('id')
            ->get(['id', 'organization_id', 'employee_id', 'project_id', 'start_date', 'end_date', 'status', 'deleted_at'])
            ->map(fn ($row): array => (array) $row)->all();
        $employees = $employeeIds === [] ? [] : DB::table('workforce_employees')
            ->where('organization_id', $command->organizationId)
            ->whereIn('id', $employeeIds)
            ->get(['id', 'organization_id', 'employment_status', 'dismissal_date'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'organization_id' => (int) $row->organization_id,
                'employee_id' => (int) $row->id,
                'employment_status' => (string) $row->employment_status,
                'dismissal_date' => $row->dismissal_date,
            ])->all();

        $result = [];
        foreach ($keys as $key) {
            $cohortAssignments = array_values(array_filter(
                $assignments,
                fn (array $row): bool => (int) $row['staff_unit_id'] === $key->staffUnitId
                    && $this->nullableInt($row['project_id']) === $key->projectId
                    && (string) $row['valid_from'] <= $key->asOfDate
                    && ($row['valid_to'] === null || (string) $row['valid_to'] >= $key->asOfDate),
            ));
            $cohortEmployeeIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['employee_id'], $cohortAssignments)));
            $cohortScheduleIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): ?int => $row['work_schedule_id'] === null ? null : (int) $row['work_schedule_id'],
                $cohortAssignments,
            ))));
            $result[$key->identity()] = [
                'staff_unit' => $staffUnits[$key->staffUnitId] ?? $this->staffUnitFromCommand($command, $key->staffUnitId),
                'assignments' => $cohortAssignments,
                'schedules' => array_values(array_filter($schedules, static fn (array $row): bool => in_array((int) $row['id'], $cohortScheduleIds, true))),
                'schedule_days' => array_values(array_filter(
                    $scheduleDays,
                    static fn (array $row): bool => in_array((int) $row['work_schedule_id'], $cohortScheduleIds, true)
                        && (string) $row['work_date'] >= $key->monthStart
                        && (string) $row['work_date'] <= (new \DateTimeImmutable($key->monthStart))->modify('last day of this month')->format('Y-m-d'),
                )),
                'absences' => array_values(array_filter($absences, fn (array $row): bool => in_array((int) $row['employee_id'], $cohortEmployeeIds, true)
                    && (string) $row['start_date'] <= $key->asOfDate
                    && (string) $row['end_date'] >= $key->asOfDate)),
                'business_trips' => array_values(array_filter($trips, fn (array $row): bool => in_array((int) $row['employee_id'], $cohortEmployeeIds, true)
                    && (string) $row['start_date'] <= $key->asOfDate
                    && (string) $row['end_date'] >= $key->asOfDate)),
                'employee_lifecycle' => array_values(array_filter($employees, static fn (array $row): bool => in_array((int) $row['employee_id'], $cohortEmployeeIds, true))),
                'gaps' => isset($staffUnits[$key->staffUnitId]) || $this->staffUnitFromCommand($command, $key->staffUnitId) !== null
                    ? []
                    : ['source_contract_missing'],
            ];
        }

        return $result;
    }

    private function relatedAssignments(WorkforceCapacityCaptureCommand $command): iterable
    {
        if ($command->sourceType === 'employee_lifecycle') {
            return array_values(array_merge(
                (array) ($command->oldState['assignments'] ?? []),
                (array) ($command->newState['assignments'] ?? []),
            ));
        }

        $staffUnitIds = $command->sourceType === 'staff_unit'
            ? $this->stateIds($command, 'id')
            : $this->stateIds($command, 'staff_unit_id');
        $scheduleIds = $command->sourceType === 'schedule'
            ? $this->stateIds($command, 'id')
            : ($command->sourceType === 'schedule_day' ? $this->stateIds($command, 'work_schedule_id') : []);
        $employeeIds = in_array($command->sourceType, ['absence', 'business_trip'], true)
            ? $this->stateIds($command, 'employee_id')
            : [];

        $query = DB::table('workforce_employee_assignments')
            ->where('organization_id', $command->organizationId);
        if ($staffUnitIds !== []) {
            $query->whereIn('staff_unit_id', $staffUnitIds);
        } elseif ($scheduleIds !== []) {
            $query->whereIn('work_schedule_id', $scheduleIds);
        } elseif ($employeeIds !== []) {
            $query->whereIn('employee_id', $employeeIds);
        } elseif ($command->sourceType === 'assignment') {
            return [];
        } else {
            throw new InvalidArgumentException('workforce_capacity_affected_source_identity_missing');
        }

        return $query->orderBy('id')->cursor();
    }

    private function stateIds(WorkforceCapacityCaptureCommand $command, string $key): array
    {
        $ids = [];
        foreach ([$command->oldState, $command->newState] as $state) {
            $id = (int) ($state[$key] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function staffUnitFromCommand(WorkforceCapacityCaptureCommand $command, int $staffUnitId): ?array
    {
        if ($command->sourceType !== 'staff_unit') {
            return null;
        }
        foreach ([$command->newState, $command->oldState] as $state) {
            if (is_array($state) && (int) ($state['id'] ?? 0) === $staffUnitId) {
                return $state;
            }
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
