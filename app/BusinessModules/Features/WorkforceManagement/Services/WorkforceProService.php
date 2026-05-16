<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkforceProService
{
    public function list(string $table, int $organizationId): Collection
    {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $record): array => $this->decorateRecord($table, $organizationId, $record));
    }

    public function store(string $table, int $organizationId, array $payload): array
    {
        $id = DB::table($table)->insertGetId(array_merge($this->normalizeJsonPayload($payload), [
            'organization_id' => $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return (array) DB::table($table)->where('id', $id)->first();
    }

    public function update(string $table, int $organizationId, int $id, array $payload): array
    {
        $this->assertRecord($table, $organizationId, $id);

        if (($payload['is_active'] ?? null) === false && in_array($table, ['workforce_departments', 'workforce_positions'], true)) {
            $this->assertNoActiveAssignmentsForStructure($table, $organizationId, $id);
        }

        DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->update(array_merge($this->normalizeJsonPayload($payload), [
            'updated_at' => now(),
        ]));

        return (array) DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->first();
    }

    public function storeStaffUnit(int $organizationId, array $payload): array
    {
        $this->assertActiveRecord('workforce_departments', $organizationId, (int) $payload['department_id']);
        $this->assertActiveRecord('workforce_positions', $organizationId, (int) $payload['position_id']);

        return $this->store('workforce_staff_units', $organizationId, $payload);
    }

    public function updateStaffUnit(int $organizationId, int $staffUnitId, array $payload): array
    {
        $current = $this->assertRecord('workforce_staff_units', $organizationId, $staffUnitId);

        if (($payload['is_active'] ?? null) === false) {
            $this->assertNoActiveAssignmentsForStaffUnit($organizationId, $staffUnitId);
        }

        if (array_key_exists('department_id', $payload)) {
            $this->assertActiveRecord('workforce_departments', $organizationId, (int) $payload['department_id']);
        }

        if (array_key_exists('position_id', $payload)) {
            $this->assertActiveRecord('workforce_positions', $organizationId, (int) $payload['position_id']);
        }

        $effectiveValidTo = $payload['valid_to'] ?? $current->valid_to;

        if ($effectiveValidTo !== null && $this->hasActiveAssignmentAfterDate($organizationId, $staffUnitId, (string) $effectiveValidTo)) {
            throw new DomainException(trans_message('workforce.errors.structure_has_active_assignments'));
        }

        return $this->update('workforce_staff_units', $organizationId, $staffUnitId, $payload);
    }

    public function storeAssignment(int $organizationId, array $payload, ?int $assignmentId = null): array
    {
        $this->assertActiveEmployee($organizationId, (int) $payload['employee_id']);
        $staffUnit = $this->assertActiveRecord('workforce_staff_units', $organizationId, (int) $payload['staff_unit_id']);
        $this->assertActiveRecord('workforce_departments', $organizationId, (int) $payload['department_id']);
        $this->assertActiveRecord('workforce_positions', $organizationId, (int) $payload['position_id']);

        if ((int) ($payload['department_id'] ?? 0) !== (int) $staffUnit->department_id || (int) ($payload['position_id'] ?? 0) !== (int) $staffUnit->position_id) {
            throw new DomainException(trans_message('workforce.errors.staff_unit_structure_mismatch'));
        }

        if (!empty($payload['work_schedule_id'])) {
            $this->assertActiveRecord('workforce_work_schedules', $organizationId, (int) $payload['work_schedule_id']);
        }

        if (!empty($payload['project_id'])) {
            $this->assertProject($organizationId, (int) $payload['project_id']);
        }

        if ($this->hasOverlappingAssignment($organizationId, (int) $payload['employee_id'], $payload['valid_from'], $payload['valid_to'] ?? null, $assignmentId)) {
            throw new DomainException(trans_message('workforce.errors.assignment_overlap'));
        }

        $this->assertAssignmentWithinStaffUnitPeriod($staffUnit, (string) $payload['valid_from'], $payload['valid_to'] ?? null);
        $this->assertStaffUnitCapacity($organizationId, (int) $payload['staff_unit_id'], $payload['valid_from'], $payload['valid_to'] ?? null, (float) ($payload['rate'] ?? 1), $assignmentId);

        return $assignmentId === null
            ? $this->store('workforce_employee_assignments', $organizationId, $payload)
            : $this->update('workforce_employee_assignments', $organizationId, $assignmentId, $payload);
    }

    public function storeScheduleDay(int $organizationId, int $scheduleId, array $payload): array
    {
        $this->assertRecord('workforce_work_schedules', $organizationId, $scheduleId);

        return $this->store('workforce_work_schedule_days', $organizationId, array_merge($payload, [
            'work_schedule_id' => $scheduleId,
        ]));
    }

    public function storeAbsence(int $organizationId, array $payload): array
    {
        $this->assertEmployee($organizationId, (int) $payload['employee_id']);
        $absenceType = DB::table('workforce_absence_types')
            ->where('organization_id', $organizationId)
            ->where('code', $payload['absence_type_code'] ?? 'vacation')
            ->first();

        if (!$absenceType) {
            $absenceTypeId = DB::table('workforce_absence_types')->insertGetId([
                'organization_id' => $organizationId,
                'code' => $payload['absence_type_code'] ?? 'vacation',
                'name' => $payload['absence_type_name'] ?? trans_message('workforce.absence_types.vacation'),
                'affects_payroll' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $absenceTypeId = $absenceType->id;
        }

        unset($payload['absence_type_code'], $payload['absence_type_name']);

        return $this->store('workforce_absences', $organizationId, array_merge($payload, [
            'absence_type_id' => $absenceTypeId,
            'status' => 'draft',
        ]));
    }

    public function storeBusinessTrip(int $organizationId, array $payload): array
    {
        $this->assertEmployee($organizationId, (int) $payload['employee_id']);

        if (!empty($payload['project_id'])) {
            $this->assertProject($organizationId, (int) $payload['project_id']);
        }

        return $this->store('workforce_business_trips', $organizationId, array_merge($payload, [
            'status' => 'draft',
        ]));
    }

    public function storeOrder(int $organizationId, array $payload): array
    {
        if (!empty($payload['employee_id'])) {
            $this->assertEmployee($organizationId, (int) $payload['employee_id']);
        }

        return $this->store('workforce_orders', $organizationId, array_merge($payload, [
            'status' => $payload['status'] ?? 'draft',
        ]));
    }

    public function approveAbsence(int $organizationId, int $absenceId): array
    {
        $absence = $this->assertRecord('workforce_absences', $organizationId, $absenceId);
        $this->assertDraftStatus($absence);
        $this->assertActiveEmployee($organizationId, (int) $absence->employee_id);

        if ($this->hasOverlappingApprovedAbsence($organizationId, (int) $absence->employee_id, (string) $absence->start_date, (string) $absence->end_date, $absenceId)) {
            throw new DomainException(trans_message('workforce.errors.absence_overlap'));
        }

        return $this->update('workforce_absences', $organizationId, $absenceId, ['status' => 'approved']);
    }

    public function cancelAbsence(int $organizationId, int $absenceId): array
    {
        $absence = $this->assertRecord('workforce_absences', $organizationId, $absenceId);

        if ($absence->status === 'cancelled') {
            return (array) $absence;
        }

        return $this->update('workforce_absences', $organizationId, $absenceId, ['status' => 'cancelled']);
    }

    public function approveBusinessTrip(int $organizationId, int $tripId): array
    {
        $trip = $this->assertRecord('workforce_business_trips', $organizationId, $tripId);
        $this->assertDraftStatus($trip);
        $this->assertActiveEmployee($organizationId, (int) $trip->employee_id);

        if ($this->hasOverlappingApprovedAbsence($organizationId, (int) $trip->employee_id, (string) $trip->start_date, (string) $trip->end_date)) {
            throw new DomainException(trans_message('workforce.errors.business_trip_absence_overlap'));
        }

        return $this->update('workforce_business_trips', $organizationId, $tripId, ['status' => 'approved']);
    }

    public function cancelBusinessTrip(int $organizationId, int $tripId): array
    {
        $trip = $this->assertRecord('workforce_business_trips', $organizationId, $tripId);

        if ($trip->status === 'cancelled') {
            return (array) $trip;
        }

        return $this->update('workforce_business_trips', $organizationId, $tripId, ['status' => 'cancelled']);
    }

    public function storePayrollPeriod(int $organizationId, int $userId, array $payload): array
    {
        if (!empty($payload['project_id'])) {
            $this->assertProject($organizationId, (int) $payload['project_id']);
        }

        if ($this->hasOverlappingPayrollPeriod($organizationId, $payload['period_start'], $payload['period_end'])) {
            throw new DomainException(trans_message('workforce.errors.payroll_period_overlap'));
        }

        return $this->store('workforce_payroll_periods', $organizationId, array_merge($payload, [
            'status' => 'draft',
            'created_by_user_id' => $userId,
        ]));
    }

    public function buildPayrollSource(int $organizationId, int $periodId): array
    {
        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        if ($period->status === 'locked') {
            throw new DomainException(trans_message('workforce.errors.payroll_period_locked'));
        }

        DB::transaction(function () use ($organizationId, $periodId, $period): void {
            DB::table('workforce_payroll_source_rows')->where('organization_id', $organizationId)->where('payroll_period_id', $periodId)->delete();

            ProductionLaborTimesheetEntry::query()
                ->with(['timesheet.workOrder', 'line'])
                ->where('organization_id', $organizationId)
                ->where('include_in_payroll', true)
                ->whereNotNull('employee_id')
                ->whereHas('timesheet', function ($query) use ($period): void {
                    $query->whereBetween('shift_date', [$period->period_start, $period->period_end]);
                })
                ->whereHas('timesheet.workOrder', function ($query) use ($period): void {
                    $query->whereIn('status', ['accepted', 'closed'])
                        ->when($period->project_id !== null, fn ($nested) => $nested->where('project_id', $period->project_id));
                })
                ->get()
                ->each(function (ProductionLaborTimesheetEntry $entry) use ($organizationId, $periodId): void {
                    $timesheet = $entry->timesheet;
                    $workOrder = $timesheet->workOrder;
                    $line = $entry->line;
                    $amount = (float) $entry->hours * (float) ($line?->hour_rate ?? 0);

                    DB::table('workforce_payroll_source_rows')->insert([
                        'organization_id' => $organizationId,
                        'payroll_period_id' => $periodId,
                        'employee_id' => $entry->employee_id,
                        'project_id' => $timesheet->project_id,
                        'work_order_id' => $entry->timesheet->work_order_id,
                        'work_order_line_id' => $entry->work_order_line_id,
                        'timesheet_entry_id' => $entry->id,
                        'work_date' => $timesheet->shift_date?->toDateString(),
                        'source_type' => 'timesheet_hours',
                        'hours' => $entry->hours,
                        'amount' => round($amount, 2),
                        'payload' => json_encode([
                            'source' => 'production-labor',
                            'work_order_number' => $workOrder?->order_number,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

            DB::table('workforce_payroll_periods')
                ->where('organization_id', $organizationId)
                ->where('id', $period->id)
                ->update([
                    'status' => 'draft',
                    'source_hash' => null,
                    'updated_at' => now(),
                ]);
        });

        return $this->payrollSourceRows($organizationId, $periodId)->all();
    }

    public function validatePayrollPeriod(int $organizationId, int $periodId): array
    {
        $period = $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        DB::transaction(function () use ($organizationId, $periodId, $period): void {
            DB::table('workforce_payroll_validation_issues')->where('organization_id', $organizationId)->where('payroll_period_id', $periodId)->delete();

            $rows = DB::table('workforce_payroll_source_rows')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $periodId)
                ->get();

            foreach ($rows as $row) {
                $assignment = $this->assignmentForDate($organizationId, (int) $row->employee_id, (string) $row->work_date);

                if (!$assignment) {
                    $this->issue($organizationId, $periodId, 'missing_assignment', trans_message('workforce.validation.missing_assignment'), $row);
                    continue;
                }

                if ($assignment->work_schedule_id === null) {
                    $this->issue($organizationId, $periodId, 'missing_work_schedule', trans_message('workforce.validation.missing_work_schedule'), $row);
                } elseif (!$this->workScheduleAllowsWorkDate($organizationId, (int) $assignment->work_schedule_id, (string) $row->work_date)) {
                    $this->issue($organizationId, $periodId, 'work_schedule_conflict', trans_message('workforce.validation.work_schedule_conflict'), $row);
                }

                $absence = DB::table('workforce_absences')
                    ->join('workforce_absence_types', 'workforce_absence_types.id', '=', 'workforce_absences.absence_type_id')
                    ->where('workforce_absences.organization_id', $organizationId)
                    ->where('workforce_absences.employee_id', $row->employee_id)
                    ->where('workforce_absences.status', 'approved')
                    ->where('workforce_absence_types.affects_payroll', true)
                    ->whereDate('workforce_absences.start_date', '<=', $row->work_date)
                    ->whereDate('workforce_absences.end_date', '>=', $row->work_date)
                    ->exists();

                if ($absence) {
                    $this->issue($organizationId, $periodId, 'absence_conflict', trans_message('workforce.validation.absence_conflict'), $row);
                }
            }

            $hasBlockingIssues = DB::table('workforce_payroll_validation_issues')
                ->where('organization_id', $organizationId)
                ->where('payroll_period_id', $periodId)
                ->where('severity', 'blocking')
                ->exists();

            DB::table('workforce_payroll_periods')
                ->where('organization_id', $organizationId)
                ->where('id', $period->id)
                ->update([
                    'status' => $hasBlockingIssues ? 'draft' : 'validated',
                    'updated_at' => now(),
                ]);
        });

        return $this->payrollValidationIssues($organizationId, $periodId)->all();
    }

    public function payrollSourceRows(int $organizationId, int $periodId): Collection
    {
        $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        return DB::table('workforce_payroll_source_rows')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('work_date')
            ->get()
            ->map(fn (object $record): array => $this->decorateRecord('workforce_payroll_source_rows', $organizationId, $record));
    }

    public function payrollValidationIssues(int $organizationId, int $periodId): Collection
    {
        $this->assertRecord('workforce_payroll_periods', $organizationId, $periodId);

        return DB::table('workforce_payroll_validation_issues')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $periodId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => $this->decorateRecord('workforce_payroll_validation_issues', $organizationId, $record));
    }

    public function assertRecord(string $table, int $organizationId, int $id): object
    {
        $record = DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->first();

        if (!$record) {
            throw new DomainException(trans_message('workforce.errors.record_not_found'));
        }

        return $record;
    }

    private function assertActiveRecord(string $table, int $organizationId, int $id): object
    {
        $record = $this->assertRecord($table, $organizationId, $id);

        if (isset($record->is_active) && (bool) $record->is_active === false) {
            throw new DomainException(trans_message('workforce.errors.structure_record_inactive'));
        }

        return $record;
    }

    private function assertEmployee(int $organizationId, int $employeeId): void
    {
        if (!WorkforceEmployee::query()->where('organization_id', $organizationId)->whereKey($employeeId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }
    }

    private function assertActiveEmployee(int $organizationId, int $employeeId): void
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->whereKey($employeeId)
            ->first();

        if (!$employee) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }

        if ($employee->employment_status !== 'active') {
            throw new DomainException(trans_message('workforce.errors.employee_not_active'));
        }
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        if (!Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.project_not_found'));
        }
    }

    private function hasOverlappingAssignment(int $organizationId, int $employeeId, string $validFrom, ?string $validTo, ?int $ignoreId = null): bool
    {
        return DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->whereDate('valid_from', '<=', $validTo ?? '9999-12-31')
            ->where(function (Builder $query) use ($validFrom): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom);
            })
            ->exists();
    }

    private function assertAssignmentWithinStaffUnitPeriod(object $staffUnit, string $validFrom, ?string $validTo): void
    {
        if ($validFrom < (string) $staffUnit->valid_from) {
            throw new DomainException(trans_message('workforce.errors.assignment_outside_staff_unit_period'));
        }

        if ($staffUnit->valid_to !== null && ($validTo === null || $validTo > (string) $staffUnit->valid_to)) {
            throw new DomainException(trans_message('workforce.errors.assignment_outside_staff_unit_period'));
        }
    }

    private function assertStaffUnitCapacity(int $organizationId, int $staffUnitId, string $validFrom, ?string $validTo, float $rate, ?int $ignoreAssignmentId = null): void
    {
        $staffUnit = $this->assertRecord('workforce_staff_units', $organizationId, $staffUnitId);
        $usedRate = (float) DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('staff_unit_id', $staffUnitId)
            ->where('status', 'active')
            ->when($ignoreAssignmentId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreAssignmentId))
            ->whereDate('valid_from', '<=', $validTo ?? '9999-12-31')
            ->where(function (Builder $query) use ($validFrom): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom);
            })
            ->sum('rate');

        if (($usedRate + $rate) > (float) $staffUnit->headcount) {
            throw new DomainException(trans_message('workforce.errors.staff_unit_capacity_exceeded'));
        }
    }

    private function assertNoActiveAssignmentsForStructure(string $table, int $organizationId, int $id): void
    {
        $field = match ($table) {
            'workforce_departments' => 'department_id',
            'workforce_positions' => 'position_id',
            default => null,
        };

        if ($field === null) {
            return;
        }

        if (DB::table('workforce_employee_assignments')->where('organization_id', $organizationId)->where($field, $id)->where('status', 'active')->exists()) {
            throw new DomainException(trans_message('workforce.errors.structure_has_active_assignments'));
        }
    }

    private function assertNoActiveAssignmentsForStaffUnit(int $organizationId, int $staffUnitId): void
    {
        if (DB::table('workforce_employee_assignments')->where('organization_id', $organizationId)->where('staff_unit_id', $staffUnitId)->where('status', 'active')->exists()) {
            throw new DomainException(trans_message('workforce.errors.structure_has_active_assignments'));
        }
    }

    private function hasActiveAssignmentAfterDate(int $organizationId, int $staffUnitId, string $date): bool
    {
        return DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('staff_unit_id', $staffUnitId)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>', $date);
            })
            ->exists();
    }

    private function hasOverlappingPayrollPeriod(int $organizationId, string $periodStart, string $periodEnd): bool
    {
        return DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)
            ->whereDate('period_start', '<=', $periodEnd)
            ->whereDate('period_end', '>=', $periodStart)
            ->exists();
    }

    private function hasOverlappingApprovedAbsence(int $organizationId, int $employeeId, string $startDate, string $endDate, ?int $ignoreAbsenceId = null): bool
    {
        return DB::table('workforce_absences')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->when($ignoreAbsenceId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreAbsenceId))
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();
    }

    private function assertDraftStatus(object $record): void
    {
        if (($record->status ?? null) !== 'draft') {
            throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
        }
    }

    private function assignmentForDate(int $organizationId, int $employeeId, string $date): ?object
    {
        return DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->first();
    }

    private function workScheduleAllowsWorkDate(int $organizationId, int $scheduleId, string $date): bool
    {
        $schedule = DB::table('workforce_work_schedules')
            ->where('organization_id', $organizationId)
            ->where('id', $scheduleId)
            ->where('is_active', true)
            ->first();

        if (!$schedule || (float) $schedule->hours_per_day <= 0) {
            return false;
        }

        $day = DB::table('workforce_work_schedule_days')
            ->where('organization_id', $organizationId)
            ->where('work_schedule_id', $scheduleId)
            ->whereDate('work_date', $date)
            ->first();

        if (!$day) {
            return true;
        }

        return $day->day_type === 'work' && (float) $day->planned_hours > 0;
    }

    private function issue(int $organizationId, int $periodId, string $code, string $message, object $row): void
    {
        DB::table('workforce_payroll_validation_issues')->insert([
            'organization_id' => $organizationId,
            'payroll_period_id' => $periodId,
            'severity' => 'blocking',
            'issue_code' => $code,
            'message' => $message,
            'entity_type' => 'payroll_source_row',
            'entity_id' => $row->id,
            'employee_id' => $row->employee_id,
            'project_id' => $row->project_id,
            'payload' => json_encode(['work_date' => $row->work_date], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decorateRecord(string $table, int $organizationId, object $record): array
    {
        $data = (array) $record;

        if (isset($record->status)) {
            $data['status_label'] = $this->statusLabel((string) $record->status);
        }

        if ($table === 'workforce_staff_units') {
            $data['department_label'] = $this->recordName('workforce_departments', $organizationId, (int) $record->department_id);
            $data['position_label'] = $this->recordName('workforce_positions', $organizationId, (int) $record->position_id);
        }

        if (in_array($table, ['workforce_absences', 'workforce_business_trips', 'workforce_payroll_source_rows', 'workforce_payroll_validation_issues'], true) && isset($record->employee_id)) {
            $data['employee_label'] = $this->employeeLabel($organizationId, (int) $record->employee_id);
        }

        if (isset($record->project_id) && $record->project_id !== null) {
            $data['project_label'] = Project::query()
                ->where('organization_id', $organizationId)
                ->whereKey((int) $record->project_id)
                ->value('name');
        }

        if ($table === 'workforce_payroll_periods') {
            $data['workflow_summary'] = [
                'label' => sprintf('%s - %s', $record->period_start, $record->period_end),
                'description' => $record->project_id === null
                    ? trans_message('workforce.workflow.payroll_period_all_projects')
                    : trans_message('workforce.workflow.payroll_period_project'),
            ];
        }

        if ($table === 'workforce_payroll_validation_issues') {
            $data['issue_label'] = trans_message("workforce.issue_labels.{$record->issue_code}");
            $data['severity_label'] = trans_message("workforce.severity_labels.{$record->severity}");
            $data['next_action_label'] = trans_message("workforce.issue_actions.{$record->issue_code}");
        }

        return $data;
    }

    private function statusLabel(string $status): string
    {
        return trans_message("workforce.statuses.{$status}");
    }

    private function recordName(string $table, int $organizationId, int $id): ?string
    {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->value('name');
    }

    private function employeeLabel(int $organizationId, int $employeeId): ?string
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->whereKey($employeeId)
            ->first(['last_name', 'first_name', 'middle_name']);

        if (!$employee) {
            return null;
        }

        return trim(implode(' ', array_filter([$employee->last_name, $employee->first_name, $employee->middle_name])));
    }

    private function normalizeJsonPayload(array $payload): array
    {
        foreach (['metadata', 'payload', 'week_pattern'] as $field) {
            if (array_key_exists($field, $payload) && is_array($payload[$field])) {
                $payload[$field] = json_encode($payload[$field], JSON_THROW_ON_ERROR);
            }
        }

        return $payload;
    }
}
