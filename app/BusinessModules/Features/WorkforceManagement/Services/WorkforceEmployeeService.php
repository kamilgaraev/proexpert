<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\AIAssistant\Services\Rag\WorkforceRagMutationBridge;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\EmploymentRelationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\BusinessModules\Features\WorkforceManagement\Domain\Scheduling\WorkforceWeekPattern;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityOwnerMutationBridge;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WorkforceEmployeeService
{
    public function __construct(private readonly WorkforceCapacityOwnerMutationBridge $capacityCapture) {}

    public function paginate(int $organizationId, int $perPage, array $filters = [], ?User $actor = null): LengthAwarePaginator
    {
        $payrollSearch = ! empty($filters['search']) && app(HrAccess::class)->allows($organizationId, $actor, 'workforce.payroll-source.manage');

        return WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->when(isset($filters['project_id']), function (Builder $query) use ($organizationId, $filters): void {
                $today = Carbon::today()->toDateString();
                $query->whereIn('id', DB::table('workforce_employee_assignments')
                    ->select('employee_id')
                    ->where('organization_id', $organizationId)
                    ->where('project_id', (int) $filters['project_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->whereDate('valid_from', '<=', $today)
                    ->where(static function ($dates) use ($today): void {
                        $dates->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today);
                    }));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('employment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) use ($payrollSearch): void {
                $query->where(function (Builder $nested) use ($search, $payrollSearch): void {
                    $nested->where('personnel_number', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%");
                    if ($payrollSearch) {
                        $nested->orWhere('external_payroll_ref', 'like', "%{$search}%");
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    public function create(int $organizationId, array $payload, ?User $actor = null): WorkforceEmployee
    {
        app(HrAccess::class)->assert($organizationId, $actor);
        $this->assertPrivateMutation($organizationId, $payload, $actor);
        $basis = $this->basis($payload);
        $payload = $this->employeePayload($payload, false);

        return DB::transaction(function () use ($organizationId, $payload, $actor, $basis): WorkforceEmployee {
            $this->lockOrganization($organizationId);
            $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
            $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null);

            $employee = WorkforceEmployee::create(array_merge($payload, [
                'organization_id' => $organizationId,
                'employment_status' => $payload['employment_status'] ?? 'active',
                'hr_reconciliation_status' => 'needs_reconciliation',
            ]));
            app(EmploymentRelationService::class)->event($organizationId, (int) $employee->id, 'personnel_card_created', $employee->hire_date->toDateString(), $basis, isset($payload['basis_document_id']) ? (int) $payload['basis_document_id'] : null, $actor, null, $employee->toArray());

            return $employee->load('user:id,name,email,current_organization_id');
        });
    }

    public function update(int $organizationId, int $employeeId, array $payload, ?User $actor = null): WorkforceEmployee
    {
        app(HrAccess::class)->assert($organizationId, $actor);
        $this->assertPrivateMutation($organizationId, $payload, $actor);
        $basis = $this->basis($payload);
        if (array_key_exists('employment_status', $payload) || array_key_exists('dismissal_date', $payload) || array_key_exists('hire_date', $payload) || array_key_exists('hr_reconciliation_status', $payload)) {
            throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
        }

        $payload = $this->employeePayload($payload, true);

        return DB::transaction(function () use ($organizationId, $employeeId, $payload, $actor, $basis): WorkforceEmployee {
            $this->lockOrganization($organizationId);
            $employee = $this->find($organizationId, $employeeId);
            $this->assertUserBelongsToOrganization($organizationId, $payload['user_id'] ?? null);
            $this->assertActiveUserAssignmentIsUnique($organizationId, $payload['user_id'] ?? null, $employeeId);
            if (array_key_exists('user_id', $payload)
                && ($payload['user_id'] === null ? null : (int) $payload['user_id']) !== ($employee->user_id === null ? null : (int) $employee->user_id)) {
                $this->assertUserRelinkDoesNotChangeFrozenSource($organizationId, $employeeId);
            }
            $before = $employee->toArray();
            $employee->update($payload);
            app(EmploymentRelationService::class)->event($organizationId, $employeeId, 'personnel_card_changed', now()->toDateString(), $basis, isset($payload['basis_document_id']) ? (int) $payload['basis_document_id'] : null, $actor, $before, $employee->refresh()->toArray());

            return $employee->refresh()->load('user:id,name,email,current_organization_id');
        });
    }

    public function dismiss(int $organizationId, int $employeeId, ?string $dismissalDate = null, ?User $actor = null, array $basisPayload = []): WorkforceEmployee
    {
        app(HrAccess::class)->assert($organizationId, $actor);
        $basis = $this->basis($basisPayload);

        return DB::transaction(function () use ($organizationId, $employeeId, $dismissalDate, $actor, $basisPayload, $basis): WorkforceEmployee {
            $this->lockOrganization($organizationId);
            $employee = $this->find($organizationId, $employeeId);
            $date = $dismissalDate ?? now()->toDateString();
            if ($employee->employment_status === 'dismissed' || Carbon::parse($date)->isFuture()) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            app(EmploymentRelationService::class)->basis($organizationId, $employeeId, isset($basisPayload['basis_document_id']) ? (int) $basisPayload['basis_document_id'] : null);
            $before = $employee->toArray();

            if ($employee->hire_date !== null && Carbon::parse($date)->lt(Carbon::parse($employee->hire_date))) {
                throw new DomainException(trans_message('workforce.errors.dismissal_before_hire_date'));
            }

            $this->assertDismissalDoesNotChangeLockedPayrollSource($organizationId, (int) $employee->id, $date);
            app(EmploymentRelationService::class)->assertTimekeepingHistoryMutable($organizationId, $employeeId, $date);
            $captureDraft = $this->capacityCapture->beginDismissal($organizationId, (int) $employee->id, $date);
            $employee->update([
                'employment_status' => 'dismissed',
                'dismissal_date' => $date,
            ]);

            $changedAssignments = DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)->where('employee_id', $employee->id)->where('status', 'active')
                ->where(function ($scope) use ($date): void {
                    $scope->whereDate('valid_from', '>', $date)->orWhere(function ($active) use ($date): void {
                        $active->whereDate('valid_from', '<=', $date)->where(function ($end) use ($date): void {
                            $end->whereNull('valid_to')->orWhereDate('valid_to', '>', $date);
                        });
                    });
                });
            app(WorkforceRagMutationBridge::class)->changedRows('workforce_employee_assignments', $organizationId, $changedAssignments);

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
            DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->where('status', 'active')
                ->whereDate('valid_from', '>', $date)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
            foreach (EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->where('status', 'active')->lockForUpdate()->get() as $relation) {
                if ($relation->start_date->toDateString() > $date) {
                    $relation->update(['status' => 'cancelled']);
                } elseif ($relation->end_date === null || $relation->end_date->toDateString() > $date) {
                    $relation->update(['end_date' => $date]);
                }
            }
            app(EmploymentRelationService::class)->event($organizationId, $employeeId, 'dismissal', $date, $basis, isset($basisPayload['basis_document_id']) ? (int) $basisPayload['basis_document_id'] : null, $actor, $before, $employee->refresh()->toArray());
            $this->capacityCapture->finishDismissal($captureDraft);

            return $employee->refresh()->load('user:id,name,email,current_organization_id');
        });
    }

    private function employeePayload(array $payload, bool $partial): array
    {
        $allowed = ['user_id', 'personnel_number', 'last_name', 'first_name', 'middle_name', 'hire_date', 'dismissal_date', 'employment_status', 'external_payroll_ref', 'phone', 'email', 'metadata', 'basis_reference', 'basis_document_id'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
        }

        return \Illuminate\Support\Facades\Validator::make($payload, [
            'user_id' => ['nullable', 'integer'], 'personnel_number' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'last_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'], 'first_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'], 'hire_date' => $partial ? ['prohibited'] : ['required', 'date'],
            'dismissal_date' => $partial ? ['prohibited'] : ['nullable', 'date', 'after_or_equal:hire_date'],
            'employment_status' => $partial ? ['prohibited'] : ['nullable', 'in:active,inactive'],
            'external_payroll_ref' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'], 'metadata' => ['nullable', 'array'],
            'basis_reference' => ['required', 'string', 'max:255'], 'basis_document_id' => ['nullable', 'integer'],
        ])->validate();
    }

    private function basis(array $payload): string
    {
        $basis = trim((string) ($payload['basis_reference'] ?? ''));
        if ($basis === '' || strlen($basis) > 1000) {
            throw new DomainException(trans_message('workforce.errors.hr_basis_required'));
        }

        return $basis;
    }

    private function assertPrivateMutation(int $organizationId, array $payload, ?User $actor): void
    {
        if (array_intersect(['phone', 'email', 'metadata'], array_keys($payload)) !== []) {
            app(HrAccess::class)->assert($organizationId, $actor, 'workforce.hr.private.write');
        }
        if (array_key_exists('external_payroll_ref', $payload)) {
            app(HrAccess::class)->assert($organizationId, $actor, 'workforce.payroll-source.manage');
        }
    }

    private function lockOrganization(int $organizationId): void
    {
        Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function find(int $organizationId, int $employeeId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->find($employeeId);

        if (! $employee) {
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
        [$correction, $confirmedScan] = $this->attendanceEvidence($organizationId, $employeeId, $date, $assignment);
        $presence = $this->presence($employee->employment_status, $date, $assignment, $schedule, $activeAbsence, $activeBusinessTrip, $correction, $confirmedScan);

        return [
            'id' => $employee->id,
            'personnel_number' => $employee->personnel_number,
            'full_name' => $employee->full_name,
            'employment_status' => $employee->employment_status,
            'employment_status_label' => trans_message("workforce.employee_statuses.{$employee->employment_status}"),
            'presence' => $presence,
            'assignment_history' => app(\App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\AssignmentHistoryService::class)->forEmployee($organizationId, $employeeId),
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

        if (! $exists) {
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

    private function assertUserRelinkDoesNotChangeFrozenSource(int $organizationId, int $employeeId): void
    {
        // The user link has no effective date: changing it switches every historical time-entry source.
        app(EmploymentRelationService::class)->assertTimekeepingHistoryMutable($organizationId, $employeeId, null);
        if (DB::table('workforce_payroll_periods as period')
            ->join('workforce_payroll_source_rows as source', 'source.payroll_period_id', '=', 'period.id')
            ->where('period.organization_id', $organizationId)->where('source.organization_id', $organizationId)
            ->where('period.status', 'locked')->where('source.employee_id', $employeeId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.payroll_period_locked'));
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
                'schedule.schedule_type',
                'schedule.week_pattern',
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
        ?array $activeBusinessTrip,
        ?object $correction = null,
        bool $confirmedScan = false
    ): array {
        $plannedHours = $this->plannedHours($assignment, $schedule, $date);
        if ($employmentStatus !== 'active') {
            return $this->presencePayload('not_at_work', $date, 0, 'employment_status', $plannedHours);
        }

        if ($correction !== null) {
            return $this->presencePayload((string) $correction->status, $date, $correction->hours !== null ? (float) $correction->hours : null, 'manual_correction', $plannedHours);
        }

        if ($confirmedScan) {
            return $this->presencePayload('at_work', $date, null, 'qr_scan', $plannedHours);
        }

        if ($activeBusinessTrip !== null) {
            return $this->presencePayload('business_trip', $date, null, 'business_trip', $plannedHours);
        }

        if ($activeAbsence !== null) {
            return $this->presencePayload('absence', $date, null, 'absence', $plannedHours);
        }

        if ($assignment === null) {
            return $this->presencePayload('not_at_work', $date, 0, 'assignment');
        }

        if ($assignment->work_schedule_id === null) {
            return $this->presencePayload('not_scheduled', $date, null, 'schedule');
        }

        if ($plannedHours === null) {
            return $this->presencePayload('unknown', $date, null, 'schedule');
        }

        if ($plannedHours === 0.0) {
            return $this->presencePayload('scheduled_day_off', $date, null, 'schedule', 0);
        }

        return $this->presencePayload('scheduled_work', $date, null, 'schedule', $plannedHours);
    }

    private function plannedHours(?object $assignment, ?object $schedule, string $date): ?float
    {
        if ($assignment === null || $assignment->work_schedule_id === null) {
            return null;
        }
        if ($schedule !== null) {
            return $schedule->day_type === 'work' ? (isset($schedule->planned_hours) ? (float) $schedule->planned_hours : null) : 0.0;
        }
        $pattern = WorkforceWeekPattern::hoursByIsoWeekday($assignment->schedule_type ?? null, $assignment->week_pattern ?? null, $assignment->schedule_hours_per_day ?? null);

        return $pattern !== [] ? (float) ($pattern[(string) Carbon::parse($date)->dayOfWeekIso] ?? 0) : null;
    }

    private function attendanceEvidence(int $organizationId, int $employeeId, string $date, ?object $assignment): array
    {
        $projectId = $assignment?->project_id;
        $correction = DB::table('workforce_attendance_corrections')->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)->whereDate('work_date', $date)
            ->where(function ($query) use ($projectId): void {
                $query->whereNull('project_id');
                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->orderByRaw('CASE WHEN project_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('created_at')->orderByDesc('id')->first();
        $confirmedScan = DB::table('workforce_attendance_scan_events')->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)->whereDate('work_date', $date)->where('result', 'confirmed')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))->exists();

        return [$correction, $confirmedScan];
    }

    private function presencePayload(string $status, string $date, ?float $hours, string $source, ?float $plannedHours = null): array
    {
        return [
            'status' => $status,
            'label' => trans_message("workforce.presence.{$status}"),
            'work_date' => $date,
            'hours' => $hours,
            'planned_hours' => $plannedHours,
            'source_label' => trans_message("workforce.presence_sources.{$source}"),
        ];
    }
}
