<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WorkforceEmployeeService
{
    public function paginate(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('employment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('personnel_number', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('external_payroll_ref', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    public function create(int $organizationId, array $payload): WorkforceEmployee
    {
        $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
        $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null);

        return WorkforceEmployee::create(array_merge($payload, [
            'organization_id' => $organizationId,
            'employment_status' => $payload['employment_status'] ?? 'active',
        ]))->load('user:id,name,email,current_organization_id');
    }

    public function update(int $organizationId, int $employeeId, array $payload): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
        $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null, $employeeId);
        $employee->update($payload);

        return $employee->refresh()->load('user:id,name,email,current_organization_id');
    }

    public function dismiss(int $organizationId, int $employeeId, ?string $dismissalDate = null): WorkforceEmployee
    {
        $employee = $this->find($organizationId, $employeeId);
        $date = $dismissalDate ?? now()->toDateString();

        if ($employee->hire_date !== null && Carbon::parse($date)->lt(Carbon::parse($employee->hire_date))) {
            throw new DomainException(trans_message('workforce.errors.dismissal_before_hire_date'));
        }

        $this->assertDismissalDoesNotChangeLockedPayrollSource($organizationId, (int) $employee->id, $date);

        DB::transaction(function () use ($employee, $organizationId, $date): void {
            $employee->update([
                'employment_status' => 'dismissed',
                'dismissal_date' => $date,
            ]);

            DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>', $date);
                })
                ->update([
                    'valid_to' => $date,
                    'updated_at' => now(),
                ]);
        });

        return $employee->refresh()->load('user:id,name,email,current_organization_id');
    }

    public function find(int $organizationId, int $employeeId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->find($employeeId);

        if (!$employee) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }

        return $employee;
    }

    public function card(int $organizationId, int $employeeId, ?string $workDate = null): array
    {
        $employee = $this->find($organizationId, $employeeId);
        $date = Carbon::parse($workDate ?? now()->toDateString())->toDateString();
        $assignment = $this->currentAssignment($organizationId, $employeeId, $date);
        $schedule = $assignment?->work_schedule_id
            ? $this->scheduleForDate($organizationId, (int) $assignment->work_schedule_id, $date)
            : null;
        $activeAbsence = $this->activeAbsence($organizationId, $employeeId, $date);
        $activeBusinessTrip = $this->activeBusinessTrip($organizationId, $employeeId, $date);
        $presence = $this->presence($employee->employment_status, $date, $assignment, $schedule, $activeAbsence, $activeBusinessTrip);

        return [
            'id' => $employee->id,
            'personnel_number' => $employee->personnel_number,
            'full_name' => $employee->full_name,
            'employment_status' => $employee->employment_status,
            'employment_status_label' => trans_message("workforce.employee_statuses.{$employee->employment_status}"),
            'presence' => $presence,
            'current_assignment' => $assignment ? [
                'id' => $assignment->id,
                'department_id' => $assignment->department_id,
                'department_label' => $assignment->department_label,
                'position_id' => $assignment->position_id,
                'position_label' => $assignment->position_label,
                'staff_unit_id' => $assignment->staff_unit_id,
                'staff_unit_label' => $assignment->staff_unit_label,
                'project_id' => $assignment->project_id,
                'project_label' => $assignment->project_label,
                'rate' => (float) $assignment->rate,
                'valid_from' => $assignment->valid_from,
                'valid_to' => $assignment->valid_to,
            ] : null,
            'current_schedule' => $assignment?->work_schedule_id ? [
                'id' => $assignment->work_schedule_id,
                'label' => $assignment->schedule_label,
                'code' => $assignment->schedule_code,
                'hours_per_day' => $assignment->schedule_hours_per_day !== null ? (float) $assignment->schedule_hours_per_day : null,
                'day_type' => $schedule?->day_type,
                'planned_hours' => $schedule?->planned_hours !== null ? (float) $schedule->planned_hours : null,
            ] : null,
            'active_absence' => $activeAbsence,
            'active_business_trip' => $activeBusinessTrip,
            'available_actions' => [
                'dismiss' => [
                    'enabled' => $employee->employment_status !== 'dismissed',
                    'label' => trans_message('workforce.actions.dismiss'),
                    'reason' => $employee->employment_status === 'dismissed'
                        ? trans_message('workforce.actions.employee_already_dismissed')
                        : null,
                ],
                'assign' => [
                    'enabled' => $employee->employment_status === 'active',
                    'label' => trans_message('workforce.actions.assign'),
                    'reason' => $employee->employment_status !== 'active'
                        ? trans_message('workforce.actions.employee_not_active')
                        : null,
                ],
            ],
        ];
    }

    private function assertUserBelongsToOrganization(int $organizationId, mixed $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = User::query()
            ->whereKey((int) $userId)
            ->where('current_organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('workforce.errors.user_not_found'));
        }
    }

    private function assertActiveUserAssignmentIsUnique(int $organizationId, mixed $userId, ?int $ignoreEmployeeId = null): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', (int) $userId)
            ->where('employment_status', 'active')
            ->when($ignoreEmployeeId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreEmployeeId))
            ->exists();

        if ($exists) {
            throw new DomainException(trans_message('workforce.errors.employee_user_already_active'));
        }
    }

    private function assertDismissalDoesNotChangeLockedPayrollSource(int $organizationId, int $employeeId, string $dismissalDate): void
    {
        $exists = DB::table('workforce_payroll_periods')
            ->join('workforce_payroll_source_rows', 'workforce_payroll_source_rows.payroll_period_id', '=', 'workforce_payroll_periods.id')
            ->where('workforce_payroll_periods.organization_id', $organizationId)
            ->where('workforce_payroll_periods.status', 'locked')
            ->where('workforce_payroll_source_rows.employee_id', $employeeId)
            ->whereDate('workforce_payroll_periods.period_start', '<=', $dismissalDate)
            ->whereDate('workforce_payroll_periods.period_end', '>=', $dismissalDate)
            ->exists();

        if ($exists) {
            throw new DomainException(trans_message('workforce.errors.dismissal_locked_payroll_period'));
        }
    }

    private function currentAssignment(int $organizationId, int $employeeId, string $date): ?object
    {
        return DB::table('workforce_employee_assignments as assignment')
            ->join('workforce_departments as department', 'department.id', '=', 'assignment.department_id')
            ->join('workforce_positions as position', 'position.id', '=', 'assignment.position_id')
            ->join('workforce_staff_units as staff_unit', 'staff_unit.id', '=', 'assignment.staff_unit_id')
            ->leftJoin('projects as project', 'project.id', '=', 'assignment.project_id')
            ->leftJoin('workforce_work_schedules as schedule', 'schedule.id', '=', 'assignment.work_schedule_id')
            ->where('assignment.organization_id', $organizationId)
            ->where('assignment.employee_id', $employeeId)
            ->where('assignment.status', 'active')
            ->whereDate('assignment.valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('assignment.valid_to')
                    ->orWhereDate('assignment.valid_to', '>=', $date);
            })
            ->orderByDesc('assignment.valid_from')
            ->select([
                'assignment.id',
                'assignment.department_id',
                'assignment.position_id',
                'assignment.staff_unit_id',
                'assignment.project_id',
                'assignment.work_schedule_id',
                'assignment.rate',
                'assignment.valid_from',
                'assignment.valid_to',
                'department.name as department_label',
                'position.name as position_label',
                'staff_unit.code as staff_unit_label',
                'project.name as project_label',
                'schedule.code as schedule_code',
                'schedule.name as schedule_label',
                'schedule.hours_per_day as schedule_hours_per_day',
            ])
            ->first();
    }

    private function scheduleForDate(int $organizationId, int $scheduleId, string $date): ?object
    {
        return DB::table('workforce_work_schedule_days')
            ->where('organization_id', $organizationId)
            ->where('work_schedule_id', $scheduleId)
            ->whereDate('work_date', $date)
            ->select(['day_type', 'planned_hours'])
            ->first();
    }

    private function activeAbsence(int $organizationId, int $employeeId, string $date): ?array
    {
        $absence = DB::table('workforce_absences as absence')
            ->join('workforce_absence_types as type', 'type.id', '=', 'absence.absence_type_id')
            ->where('absence.organization_id', $organizationId)
            ->where('absence.employee_id', $employeeId)
            ->where('absence.status', 'approved')
            ->whereDate('absence.start_date', '<=', $date)
            ->whereDate('absence.end_date', '>=', $date)
            ->select([
                'absence.id',
                'absence.start_date',
                'absence.end_date',
                'absence.status',
                'type.code as type_code',
                'type.name as type_label',
            ])
            ->first();

        return $absence ? [
            'id' => $absence->id,
            'type_code' => $absence->type_code,
            'type_label' => $absence->type_label,
            'start_date' => $absence->start_date,
            'end_date' => $absence->end_date,
            'status' => $absence->status,
        ] : null;
    }

    private function activeBusinessTrip(int $organizationId, int $employeeId, string $date): ?array
    {
        $trip = DB::table('workforce_business_trips')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->select(['id', 'project_id', 'start_date', 'end_date', 'destination', 'purpose', 'status'])
            ->first();

        return $trip ? [
            'id' => $trip->id,
            'project_id' => $trip->project_id,
            'start_date' => $trip->start_date,
            'end_date' => $trip->end_date,
            'destination' => $trip->destination,
            'purpose' => $trip->purpose,
            'status' => $trip->status,
        ] : null;
    }

    private function presence(
        string $employmentStatus,
        string $date,
        ?object $assignment,
        ?object $schedule,
        ?array $activeAbsence,
        ?array $activeBusinessTrip
    ): array {
        if ($employmentStatus !== 'active') {
            return $this->presencePayload('not_at_work', $date, 0, 'employment_status');
        }

        if ($activeBusinessTrip !== null) {
            return $this->presencePayload('business_trip', $date, null, 'business_trip');
        }

        if ($activeAbsence !== null) {
            return $this->presencePayload('absence', $date, null, 'absence');
        }

        if ($assignment === null) {
            return $this->presencePayload('not_at_work', $date, 0, 'assignment');
        }

        if ($assignment->work_schedule_id === null) {
            return $this->presencePayload('not_scheduled', $date, null, 'schedule');
        }

        if ($schedule !== null && $schedule->day_type !== 'work') {
            return $this->presencePayload('scheduled_day_off', $date, (float) $schedule->planned_hours, 'schedule');
        }

        $hours = $schedule?->planned_hours ?? $assignment->schedule_hours_per_day ?? 8;

        return $this->presencePayload('at_work', $date, (float) $hours, 'schedule');
    }

    private function presencePayload(string $status, string $date, ?float $hours, string $source): array
    {
        return [
            'status' => $status,
            'label' => trans_message("workforce.presence.{$status}"),
            'work_date' => $date,
            'hours' => $hours,
            'source_label' => trans_message("workforce.presence_sources.{$source}"),
        ];
    }
}
