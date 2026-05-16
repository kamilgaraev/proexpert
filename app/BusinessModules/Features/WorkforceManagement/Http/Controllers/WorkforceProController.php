<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkforceProController extends Controller
{
    public function __construct(
        private readonly WorkforceProService $service,
        private readonly WorkforceAttendanceService $attendanceService
    ) {
    }

    public function departments(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_departments');
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        return $this->store($request, 'workforce_departments', $this->departmentRules());
    }

    public function updateDepartment(Request $request, int $departmentId): JsonResponse
    {
        return $this->update($request, 'workforce_departments', $departmentId, $this->departmentRules(partial: true));
    }

    public function positions(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_positions');
    }

    public function storePosition(Request $request): JsonResponse
    {
        return $this->store($request, 'workforce_positions', $this->positionRules());
    }

    public function updatePosition(Request $request, int $positionId): JsonResponse
    {
        return $this->update($request, 'workforce_positions', $positionId, $this->positionRules(partial: true));
    }

    public function staffUnits(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_staff_units');
    }

    public function storeStaffUnit(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeStaffUnit($this->organizationId($request), $request->validate($this->staffUnitRules())), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'staff_units.store');
        }
    }

    public function updateStaffUnit(Request $request, int $staffUnitId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->updateStaffUnit($this->organizationId($request), $staffUnitId, $request->validate($this->staffUnitRules(partial: true))), trans_message('workforce.messages.record_updated'));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'staff_units.update');
        }
    }

    public function storeEmployeeAssignment(Request $request): JsonResponse
    {
        return $this->assignment($request);
    }

    public function updateEmployeeAssignment(Request $request, int $assignmentId): JsonResponse
    {
        return $this->assignment($request, $assignmentId);
    }

    public function scheduleCalendar(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'date_from' => ['required', 'date'],
                'date_to' => ['required', 'date', 'after_or_equal:date_from'],
                'project_id' => ['nullable', 'integer'],
            ]);

            return AdminResponse::success($this->service->scheduleCalendar(
                $this->organizationId($request),
                (string) $payload['date_from'],
                (string) $payload['date_to'],
                isset($payload['project_id']) ? (int) $payload['project_id'] : null
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'schedule_calendar.index');
        }
    }

    public function attendanceSheet(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'date_from' => ['required', 'date'],
                'date_to' => ['required', 'date', 'after_or_equal:date_from'],
                'project_id' => ['nullable', 'integer'],
            ]);

            return AdminResponse::success($this->attendanceService->sheet(
                $this->organizationId($request),
                (string) $payload['date_from'],
                (string) $payload['date_to'],
                isset($payload['project_id']) ? (int) $payload['project_id'] : null
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance_sheet.index');
        }
    }

    public function attendanceCorrections(Request $request, int $employeeId): JsonResponse
    {
        try {
            return AdminResponse::success($this->attendanceService->history($this->organizationId($request), $employeeId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance_corrections.index');
        }
    }

    public function storeAttendanceCorrection(Request $request, int $employeeId): JsonResponse
    {
        try {
            $payload = $request->validate([
                'work_date' => ['required', 'date'],
                'project_id' => ['nullable', 'integer'],
                'status' => ['required', Rule::in(['at_work', 'not_at_work', 'scheduled_day_off', 'absence', 'business_trip', 'not_scheduled'])],
                'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
                'reason' => ['required', 'string', 'min:3', 'max:500'],
            ]);

            return AdminResponse::success($this->attendanceService->storeCorrection(
                $this->organizationId($request),
                $employeeId,
                (int) $request->user()?->id,
                $payload
            ), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance_corrections.store');
        }
    }

    public function workSchedules(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_work_schedules');
    }

    public function storeWorkSchedule(Request $request): JsonResponse
    {
        return $this->store($request, 'workforce_work_schedules', $this->scheduleRules());
    }

    public function storeWorkScheduleDay(Request $request, int $scheduleId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeScheduleDay($this->organizationId($request), $scheduleId, $request->validate([
                'work_date' => ['required', 'date'],
                'day_type' => ['nullable', Rule::in(['work', 'weekend', 'holiday'])],
                'planned_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
                'comment' => ['nullable', 'string', 'max:255'],
            ])), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'schedule_days.store');
        }
    }

    public function absences(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_absences');
    }

    public function storeAbsence(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeAbsence($this->organizationId($request), $request->validate($this->absenceRules())), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'absences.store');
        }
    }

    public function approveAbsence(Request $request, int $absenceId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->approveAbsence($this->organizationId($request), $absenceId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'absences.approve');
        }
    }

    public function cancelAbsence(Request $request, int $absenceId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->cancelAbsence($this->organizationId($request), $absenceId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'absences.cancel');
        }
    }

    public function businessTrips(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_business_trips');
    }

    public function storeBusinessTrip(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeBusinessTrip($this->organizationId($request), $request->validate($this->businessTripRules())), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'business_trips.store');
        }
    }

    public function approveBusinessTrip(Request $request, int $tripId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->approveBusinessTrip($this->organizationId($request), $tripId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'business_trips.approve');
        }
    }

    public function cancelBusinessTrip(Request $request, int $tripId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->cancelBusinessTrip($this->organizationId($request), $tripId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'business_trips.cancel');
        }
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_orders');
    }

    public function storeOrder(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeOrder($this->organizationId($request), $request->validate($this->orderRules())), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'orders.store');
        }
    }

    public function approveOrder(Request $request, int $orderId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->approveOrder($this->organizationId($request), $orderId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'orders.approve');
        }
    }

    public function applyOrder(Request $request, int $orderId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->applyOrder($this->organizationId($request), $orderId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'orders.apply');
        }
    }

    public function cancelOrder(Request $request, int $orderId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->cancelOrder($this->organizationId($request), $orderId), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'orders.cancel');
        }
    }

    public function payrollPeriods(Request $request): JsonResponse
    {
        return $this->list($request, 'workforce_payroll_periods');
    }

    public function storePayrollPeriod(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storePayrollPeriod($this->organizationId($request), (int) $request->user()?->id, $request->validate([
                'period_start' => ['required', 'date'],
                'period_end' => ['required', 'date', 'after_or_equal:period_start'],
                'project_id' => ['nullable', 'integer'],
            ])), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_periods.store');
        }
    }

    public function showPayrollPeriod(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->assertRecord('workforce_payroll_periods', $this->organizationId($request), $periodId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        }
    }

    public function buildPayrollSource(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->buildPayrollSource($this->organizationId($request), $periodId), trans_message('workforce.messages.payroll_source_built'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_source.build');
        }
    }

    public function validatePayrollPeriod(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->validatePayrollPeriod($this->organizationId($request), $periodId), trans_message('workforce.messages.payroll_period_validated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_source.validate');
        }
    }

    public function payrollSourceRows(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->payrollSourceRows($this->organizationId($request), $periodId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_source.rows');
        }
    }

    public function payrollValidationIssues(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->payrollValidationIssues($this->organizationId($request), $periodId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_source.issues');
        }
    }

    private function list(Request $request, string $table): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->list($table, $this->organizationId($request)));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, "{$table}.index");
        }
    }

    private function store(Request $request, string $table, array $rules): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->store($table, $this->organizationId($request), $request->validate($rules)), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, "{$table}.store");
        }
    }

    private function update(Request $request, string $table, int $id, array $rules): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->update($table, $this->organizationId($request), $id, $request->validate($rules)), trans_message('workforce.messages.record_updated'));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, "{$table}.update");
        }
    }

    private function assignment(Request $request, ?int $assignmentId = null): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeAssignment($this->organizationId($request), $request->validate($this->assignmentRules()), $assignmentId), trans_message('workforce.messages.record_created'), $assignmentId === null ? 201 : 200);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employee_assignments.store');
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function departmentRules(bool $partial = false): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function positionRules(bool $partial = false): array
    {
        return [
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function staffUnitRules(bool $partial = false): array
    {
        return [
            'department_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'position_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'headcount' => ['nullable', 'numeric', 'min:0.01'],
            'rate' => ['nullable', 'numeric', 'min:0.01'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => [$partial ? 'sometimes' : 'required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function assignmentRules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'staff_unit_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer'],
            'position_id' => ['required', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'work_schedule_id' => ['nullable', 'integer'],
            'rate' => ['nullable', 'numeric', 'min:0.01'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['nullable', Rule::in(['active', 'closed'])],
        ];
    }

    private function scheduleRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'schedule_type' => ['nullable', 'string', 'max:40'],
            'hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'week_pattern' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function absenceRules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'absence_type_code' => ['nullable', 'string', 'max:80'],
            'absence_type_name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'applied', 'cancelled'])],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function businessTripRules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'destination' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'cancelled'])],
        ];
    }

    private function orderRules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date'],
            'order_type' => ['required', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'cancelled'])],
            'payload' => ['nullable', 'array'],
        ];
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('workforce.pro_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('workforce.errors.unexpected'), 500);
    }
}
