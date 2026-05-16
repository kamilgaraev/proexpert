<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\Models\Project;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkforceAttendanceService
{
    public function sheet(int $organizationId, string $dateFrom, string $dateTo, ?int $projectId = null): array
    {
        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
        }

        $start = CarbonImmutable::parse($dateFrom)->startOfDay();
        $end = CarbonImmutable::parse($dateTo)->startOfDay();
        $days = $this->days($start, $end);
        $assignments = $this->assignments($organizationId, $start, $end, $projectId);
        $employeeIds = $assignments->pluck('employee_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $scheduleDays = $this->scheduleDays($organizationId, $assignments, $start, $end);
        $corrections = $this->correctionsForPeriod($organizationId, $employeeIds, $start, $end, $projectId);

        return [
            'days' => $days,
            'rows' => $assignments
                ->map(fn (object $assignment): array => $this->row($assignment, $days, $scheduleDays, $corrections))
                ->values()
                ->all(),
        ];
    }

    public function history(int $organizationId, int $employeeId): array
    {
        $this->assertEmployee($organizationId, $employeeId);

        return DB::table('workforce_attendance_corrections as correction')
            ->leftJoin('projects as project', 'project.id', '=', 'correction.project_id')
            ->leftJoin('users as author', 'author.id', '=', 'correction.created_by_user_id')
            ->where('correction.organization_id', $organizationId)
            ->where('correction.employee_id', $employeeId)
            ->orderByDesc('correction.work_date')
            ->orderByDesc('correction.created_at')
            ->select([
                'correction.id',
                'correction.employee_id',
                'correction.project_id',
                'project.name as project_label',
                'correction.work_date',
                'correction.status',
                'correction.hours',
                'correction.reason',
                'correction.created_at',
                'author.name as author_label',
            ])
            ->get()
            ->map(fn (object $record): array => $this->correctionPayload($record))
            ->all();
    }

    public function storeCorrection(int $organizationId, int $employeeId, int $userId, array $payload): array
    {
        $employee = $this->assertEmployee($organizationId, $employeeId);
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;

        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
        }

        $workDate = (string) $payload['work_date'];

        if ($employee->dismissal_date !== null && $workDate > (string) $employee->dismissal_date) {
            throw new DomainException(trans_message('workforce.errors.attendance_after_dismissal'));
        }

        $this->assertPayrollPeriodOpen($organizationId, $workDate, $projectId);

        if ((string) $payload['status'] === 'at_work' && $this->hasApprovedAbsence($organizationId, $employeeId, $workDate)) {
            throw new DomainException(trans_message('workforce.errors.attendance_conflicts_with_absence'));
        }

        $id = DB::table('workforce_attendance_corrections')->insertGetId([
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'project_id' => $projectId,
            'work_date' => $workDate,
            'status' => (string) $payload['status'],
            'hours' => array_key_exists('hours', $payload) && $payload['hours'] !== null ? (float) $payload['hours'] : null,
            'reason' => (string) $payload['reason'],
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = DB::table('workforce_attendance_corrections as correction')
            ->leftJoin('projects as project', 'project.id', '=', 'correction.project_id')
            ->leftJoin('users as author', 'author.id', '=', 'correction.created_by_user_id')
            ->where('correction.organization_id', $organizationId)
            ->where('correction.id', $id)
            ->select([
                'correction.id',
                'correction.employee_id',
                'correction.project_id',
                'project.name as project_label',
                'correction.work_date',
                'correction.status',
                'correction.hours',
                'correction.reason',
                'correction.created_at',
                'author.name as author_label',
            ])
            ->first();

        if ($record === null) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        return $this->correctionPayload($record);
    }

    private function assignments(int $organizationId, CarbonImmutable $start, CarbonImmutable $end, ?int $projectId): Collection
    {
        return DB::table('workforce_employee_assignments as assignment')
            ->join('workforce_employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->join('workforce_departments as department', 'department.id', '=', 'assignment.department_id')
            ->join('workforce_positions as position', 'position.id', '=', 'assignment.position_id')
            ->leftJoin('workforce_work_schedules as schedule', 'schedule.id', '=', 'assignment.work_schedule_id')
            ->where('assignment.organization_id', $organizationId)
            ->where('assignment.status', 'active')
            ->whereNull('assignment.deleted_at')
            ->whereNull('employee.deleted_at')
            ->whereDate('assignment.valid_from', '<=', $end->toDateString())
            ->where(function (Builder $query) use ($start): void {
                $query->whereNull('assignment.valid_to')->orWhereDate('assignment.valid_to', '>=', $start->toDateString());
            })
            ->when($projectId !== null, fn (Builder $query) => $query->where('assignment.project_id', $projectId))
            ->orderBy('employee.last_name')
            ->orderBy('employee.first_name')
            ->select([
                'assignment.id',
                'assignment.employee_id',
                'assignment.project_id',
                'assignment.work_schedule_id',
                'assignment.valid_from',
                'assignment.valid_to',
                'employee.last_name',
                'employee.first_name',
                'employee.middle_name',
                'department.name as department_label',
                'position.name as position_label',
                'schedule.hours_per_day',
            ])
            ->get();
    }

    private function scheduleDays(int $organizationId, Collection $assignments, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $scheduleIds = $assignments->pluck('work_schedule_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        if (empty($scheduleIds)) {
            return collect();
        }

        return DB::table('workforce_work_schedule_days')
            ->where('organization_id', $organizationId)
            ->whereIn('work_schedule_id', $scheduleIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (object $record): string => $record->work_schedule_id . ':' . $record->work_date);
    }

    private function correctionsForPeriod(int $organizationId, array $employeeIds, CarbonImmutable $start, CarbonImmutable $end, ?int $projectId): Collection
    {
        if (empty($employeeIds)) {
            return collect();
        }

        return DB::table('workforce_attendance_corrections')
            ->where('organization_id', $organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->orderBy('created_at')
            ->get()
            ->keyBy(fn (object $record): string => $record->employee_id . ':' . $record->work_date . ':' . ($record->project_id ?? 'all'));
    }

    private function row(object $assignment, array $days, Collection $scheduleDays, Collection $corrections): array
    {
        $rowDays = [];

        foreach ($days as $day) {
            $date = (string) $day['date'];
            $rowDays[$date] = $this->day($assignment, $date, $scheduleDays, $corrections);
        }

        return [
            'employee_id' => (int) $assignment->employee_id,
            'employee_label' => trim(implode(' ', array_filter([$assignment->last_name, $assignment->first_name, $assignment->middle_name]))),
            'assignment_label' => sprintf('%s / %s', $assignment->position_label, $assignment->department_label),
            'project_id' => $assignment->project_id !== null ? (int) $assignment->project_id : null,
            'days' => $rowDays,
        ];
    }

    private function day(object $assignment, string $date, Collection $scheduleDays, Collection $corrections): array
    {
        $correction = $corrections->get($assignment->employee_id . ':' . $date . ':' . ($assignment->project_id ?? 'all'))
            ?? $corrections->get($assignment->employee_id . ':' . $date . ':all');

        if ($correction !== null) {
            return $this->presence((string) $correction->status, $date, $correction->hours !== null ? (float) $correction->hours : null, 'manual_correction');
        }

        if ($date < (string) $assignment->valid_from || ($assignment->valid_to !== null && $date > (string) $assignment->valid_to)) {
            return $this->presence('not_at_work', $date, 0, 'assignment');
        }

        if ($assignment->work_schedule_id === null) {
            return $this->presence('not_scheduled', $date, null, 'schedule');
        }

        $scheduleDay = $scheduleDays->get($assignment->work_schedule_id . ':' . $date);

        if ($scheduleDay !== null && $scheduleDay->day_type !== 'work') {
            return $this->presence('scheduled_day_off', $date, 0, 'schedule');
        }

        return $this->presence('at_work', $date, $this->hours($scheduleDay?->planned_hours ?? $assignment->hours_per_day ?? 8), 'schedule');
    }

    private function correctionPayload(object $record): array
    {
        return [
            'id' => (int) $record->id,
            'employee_id' => (int) $record->employee_id,
            'project_id' => $record->project_id !== null ? (int) $record->project_id : null,
            'project_label' => $record->project_label,
            'work_date' => (string) $record->work_date,
            'status' => (string) $record->status,
            'status_label' => trans_message('workforce.presence.' . $record->status),
            'hours' => $record->hours !== null ? $this->hours($record->hours) : null,
            'reason' => (string) $record->reason,
            'source_label' => trans_message('workforce.presence_sources.manual_correction'),
            'author_label' => $record->author_label,
            'created_at' => $record->created_at,
        ];
    }

    private function presence(string $status, string $date, int|float|null $hours, string $source): array
    {
        return [
            'status' => $status,
            'status_label' => trans_message("workforce.presence.{$status}"),
            'work_date' => $date,
            'hours' => $hours,
            'source_label' => trans_message("workforce.presence_sources.{$source}"),
        ];
    }

    private function days(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $weekdays = [
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Вс',
        ];

        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'label' => $date->format('d.m'),
                'weekday' => $weekdays[$date->dayOfWeekIso],
            ];
        }

        return $days;
    }

    private function hours(mixed $value): int|float
    {
        $hours = (float) $value;

        return floor($hours) === $hours ? (int) $hours : $hours;
    }

    private function assertEmployee(int $organizationId, int $employeeId): object
    {
        $employee = DB::table('workforce_employees')
            ->where('organization_id', $organizationId)
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }

        return $employee;
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        if (! Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.project_not_found'));
        }
    }

    private function assertPayrollPeriodOpen(int $organizationId, string $workDate, ?int $projectId): void
    {
        $exists = DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)
            ->where('status', 'locked')
            ->whereDate('period_start', '<=', $workDate)
            ->whereDate('period_end', '>=', $workDate)
            ->where(function (Builder $query) use ($projectId): void {
                $query->whereNull('project_id');

                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->exists();

        if ($exists) {
            throw new DomainException(trans_message('workforce.errors.payroll_period_locked'));
        }
    }

    private function hasApprovedAbsence(int $organizationId, int $employeeId, string $workDate): bool
    {
        return DB::table('workforce_absences')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->whereDate('start_date', '<=', $workDate)
            ->whereDate('end_date', '>=', $workDate)
            ->exists();
    }
}
