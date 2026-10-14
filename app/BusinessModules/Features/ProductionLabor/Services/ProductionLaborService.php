<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Services;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use App\Services\WorkforcePeriodMutationGuard;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionLaborService
{
    public function __construct(
        private readonly SafetyComplianceService $safetyComplianceService,
    ) {}

    public function paginateWorkOrders(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ProductionLaborWorkOrder::query()
            ->with(['project:id,name', 'lines'])
            ->where('organization_id', $organizationId)
            ->when(array_key_exists('project_ids', $filters), fn ($query) => $query->whereIn('project_id', $filters['project_ids']))
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }

    public function paginateOutputEntries(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ProductionLaborOutputEntry::query()
            ->with(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name'])
            ->where('organization_id', $organizationId)
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['work_order_id'] ?? null, fn ($query, $workOrderId) => $query->where('work_order_id', $workOrderId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('work_date')
            ->paginate($perPage);
    }

    public function paginatePayrollAccruals(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ProductionLaborPayrollAccrual::query()
            ->with(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name'])
            ->where('organization_id', $organizationId)
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }

    public function paginateTimesheets(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ProductionLaborTimesheet::query()
            ->with(['workOrder:id,order_number,title', 'entries.line:id,name,requires_safety_permit', 'entries.employee'])
            ->where('organization_id', $organizationId)
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['work_order_id'] ?? null, fn ($query, $workOrderId) => $query->where('work_order_id', $workOrderId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('shift_date')
            ->paginate($perPage);
    }

    public function createWorkOrder(int $organizationId, int $userId, array $payload): ProductionLaborWorkOrder
    {
        $this->assertProject($organizationId, (int) $payload['project_id']);

        return DB::transaction(function () use ($organizationId, $userId, $payload): ProductionLaborWorkOrder {
            $this->lockOrganization($organizationId);
            $workOrder = ProductionLaborWorkOrder::create([
                'organization_id' => $organizationId,
                'project_id' => (int) $payload['project_id'],
                'schedule_task_id' => $payload['schedule_task_id'] ?? null,
                'contractor_id' => $payload['contractor_id'] ?? null,
                'created_by_user_id' => $userId,
                'order_number' => $payload['order_number'],
                'title' => $payload['title'],
                'assignee_type' => $payload['assignee_type'],
                'assignee_name' => $payload['assignee_name'] ?? null,
                'planned_start_date' => $payload['planned_start_date'] ?? null,
                'planned_finish_date' => $payload['planned_finish_date'] ?? null,
                'status' => 'draft',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            foreach ($payload['lines'] as $line) {
                $workOrder->lines()->create([
                    'organization_id' => $organizationId,
                    'work_type_id' => $line['work_type_id'] ?? null,
                    'estimate_item_id' => $line['estimate_item_id'] ?? null,
                    'schedule_task_id' => $line['schedule_task_id'] ?? $workOrder->schedule_task_id,
                    'name' => $line['name'],
                    'unit' => $line['unit'] ?? 'unit',
                    'planned_quantity' => $line['planned_quantity'],
                    'unit_rate' => $line['unit_rate'] ?? 0,
                    'planned_hours' => $line['planned_hours'] ?? 0,
                    'hour_rate' => $line['hour_rate'] ?? 0,
                    'pay_basis' => $line['pay_basis'] ?? 'volume',
                    'requires_safety_permit' => $line['requires_safety_permit'] ?? false,
                    'metadata' => $line['metadata'] ?? null,
                ]);
            }

            return $workOrder->load(['project:id,name', 'lines']);
        });
    }

    public function issueWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if ($workOrder->status !== 'draft') {
                throw new DomainException(trans_message('production_labor.errors.issue_invalid_status'));
            }

            $workOrder->update(['status' => 'issued', 'issued_at' => now()]);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function startWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if ($workOrder->status !== 'issued') {
                throw new DomainException(trans_message('production_labor.errors.start_invalid_status'));
            }

            $workOrder->update(['status' => 'in_progress']);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function submitWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if (! in_array($workOrder->status, ['issued', 'in_progress'], true)) {
                throw new DomainException(trans_message('production_labor.errors.submit_invalid_status'));
            }

            $workOrder->update(['status' => 'submitted', 'submitted_at' => now()]);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function acceptWorkOrder(ProductionLaborWorkOrder $workOrder, int $userId): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $userId): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);

            if ($workOrder->status !== 'submitted') {
                throw new DomainException(trans_message('production_labor.errors.accept_invalid_status'));
            }

            DB::table('production_labor_timesheets as timesheet')
                ->join('production_labor_timesheet_entries as entry', 'entry.timesheet_id', '=', 'timesheet.id')
                ->where('timesheet.organization_id', $workOrder->organization_id)
                ->where('timesheet.work_order_id', $workOrder->id)
                ->where('entry.include_in_payroll', true)
                ->whereNull('timesheet.deleted_at')
                ->whereNull('entry.deleted_at')
                ->distinct()
                ->pluck('timesheet.shift_date')
                ->each(fn (string $workDate) => $this->assertPayrollPeriodOpenForWorkDate(
                    (int) $workOrder->organization_id,
                    (int) $workOrder->project_id,
                    $workDate,
                ));

            $workOrder->update([
                'status' => 'accepted',
                'accepted_by_user_id' => $userId,
                'accepted_at' => now(),
            ]);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function returnWorkOrder(ProductionLaborWorkOrder $workOrder, string $reason): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $reason): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if ($workOrder->status !== 'submitted') {
                throw new DomainException(trans_message('production_labor.errors.return_invalid_status'));
            }

            $workOrder->update(['status' => 'returned', 'return_reason' => $reason]);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function closeWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if ($workOrder->status !== 'accepted') {
                throw new DomainException(trans_message('production_labor.errors.close_invalid_status'));
            }

            $workOrder->update(['status' => 'closed', 'closed_at' => now()]);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function cancelWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        return DB::transaction(function () use ($workOrder): ProductionLaborWorkOrder {
            $this->lockOrganization((int) $workOrder->organization_id);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $workOrder->organization_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkOrderSourcesMutable($workOrder);
            if (! in_array($workOrder->status, ['issued', 'in_progress'], true)) {
                throw new DomainException(trans_message('production_labor.errors.cancel_invalid_status'));
            }

            $workOrder->update(['status' => 'cancelled']);

            return $workOrder->refresh()->load(['project:id,name', 'lines']);
        });
    }

    public function recordOutput(int $organizationId, int $userId, array $payload, bool $allowOverrun = false, ?string $idempotencyKey = null): ProductionLaborOutputEntry
    {
        $fingerprint = $idempotencyKey === null ? null : hash('sha256', json_encode([
            'work_order_line_id' => (int) $payload['work_order_line_id'],
            'work_date' => (string) $payload['work_date'],
            'quantity' => number_format((float) $payload['quantity'], 4, '.', ''),
            'hours' => number_format((float) $payload['hours'], 2, '.', ''),
            'comment' => $payload['comment'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organizationId, $userId, $payload, $allowOverrun, $idempotencyKey, $fingerprint): ProductionLaborOutputEntry {
            $this->lockOrganization($organizationId);
            if ($idempotencyKey !== null) {
                $existing = ProductionLaborOutputEntry::withTrashed()
                    ->where('organization_id', $organizationId)
                    ->where('recorded_by_user_id', $userId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    if ($existing->trashed() || $existing->payload_fingerprint !== $fingerprint) {
                        throw new DomainException(trans_message('production_labor.errors.idempotency_conflict'), 409);
                    }

                    $this->assertActorProject($organizationId, $userId, (int) $existing->project_id, $payload['project_id'] ?? null);

                    return $existing->load(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name']);
                }
            }

            $line = $this->findLine($organizationId, (int) $payload['work_order_line_id']);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $organizationId)->whereKey($line->work_order_id)->lockForUpdate()->firstOrFail();
            $line = ProductionLaborWorkOrderLine::query()->where('organization_id', $organizationId)->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertActorProject($organizationId, $userId, (int) $workOrder->project_id, $payload['project_id'] ?? null);
            if (! in_array($workOrder->status, ['issued', 'in_progress'], true)) {
                throw new DomainException(trans_message('production_labor.errors.output_invalid_status'));
            }

            app(WorkforcePeriodMutationGuard::class)->assertMutable($organizationId, (int) $workOrder->project_id, ['work_date' => $payload['work_date'], 'user_id' => $userId]);
            $quantity = (float) $payload['quantity'];
            $planned = (float) $line->planned_quantity;
            if (! $allowOverrun && $planned > 0 && round((float) $line->accepted_quantity + $quantity, 4) > $planned) {
                throw new DomainException(trans_message('production_labor.errors.output_over_plan'));
            }

            $this->assertPayrollPeriodOpenForWorkDate(
                $organizationId,
                (int) $workOrder->project_id,
                $payload['work_date']
            );

            $entry = ProductionLaborOutputEntry::create([
                'organization_id' => $organizationId,
                'work_order_id' => $workOrder->id,
                'work_order_line_id' => $line->id,
                'project_id' => $workOrder->project_id,
                'schedule_task_id' => $line->schedule_task_id ?? $workOrder->schedule_task_id,
                'recorded_by_user_id' => $userId,
                'work_date' => $payload['work_date'],
                'quantity' => $payload['quantity'],
                'hours' => $payload['hours'],
                'status' => 'accepted',
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'comment' => $payload['comment'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'payload_fingerprint' => $fingerprint,
            ]);

            $line->update(['accepted_quantity' => (float) $line->accepted_quantity + (float) $payload['quantity']]);

            return $entry->load(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name']);
        });
    }

    public function createTimesheet(int $organizationId, int $userId, array $payload): ProductionLaborTimesheet
    {
        $workOrder = $this->findWorkOrder($organizationId, (int) $payload['work_order_id']);
        $this->assertActorProject($organizationId, $userId, (int) $workOrder->project_id, $payload['project_id'] ?? null);

        if (! in_array($workOrder->status, ['issued', 'in_progress'], true)) {
            throw new DomainException(trans_message('production_labor.errors.timesheet_invalid_status'));
        }

        return DB::transaction(function () use ($organizationId, $userId, $payload, $workOrder): ProductionLaborTimesheet {
            $this->lockOrganization($organizationId);
            $workOrder = ProductionLaborWorkOrder::query()->where('organization_id', $organizationId)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            if (! in_array($workOrder->status, ['issued', 'in_progress'], true)) {
                throw new DomainException(trans_message('production_labor.errors.timesheet_invalid_status'));
            }
            $this->assertPayrollPeriodOpenForWorkDate(
                $organizationId,
                (int) $workOrder->project_id,
                $payload['shift_date']
            );

            $timesheet = ProductionLaborTimesheet::create([
                'organization_id' => $organizationId,
                'work_order_id' => $workOrder->id,
                'project_id' => $workOrder->project_id,
                'created_by_user_id' => $userId,
                'shift_date' => $payload['shift_date'],
                'status' => 'submitted',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            foreach ($payload['entries'] as $entryPayload) {
                $line = $this->findLine($organizationId, (int) $entryPayload['work_order_line_id']);
                $includeInPayroll = $entryPayload['include_in_payroll'] ?? true;
                $workerName = trim((string) ($entryPayload['worker_name'] ?? ''));

                if ((bool) $includeInPayroll && $workerName !== '') {
                    throw new DomainException(trans_message('production_labor.errors.worker_name_not_allowed_for_payroll'));
                }

                if (! (bool) $includeInPayroll && $workerName === '') {
                    throw new DomainException(trans_message('production_labor.errors.worker_or_brigade_required'));
                }

                $employee = $this->resolveTimesheetEmployee(
                    $organizationId,
                    $entryPayload['employee_id'] ?? null,
                    $payload['shift_date'],
                    (bool) $includeInPayroll
                );

                if ((int) $line->work_order_id !== (int) $workOrder->id) {
                    throw new DomainException(trans_message('production_labor.errors.line_not_in_order'));
                }

                if ($line->requires_safety_permit) {
                    $safetyPermitReference = trim((string) ($entryPayload['safety_permit_reference'] ?? ''));

                    if ($safetyPermitReference === '') {
                        throw new DomainException(trans_message('production_labor.errors.safety_permit_required'));
                    }

                    $this->assertActiveSafetyPermit(
                        $organizationId,
                        (int) $workOrder->project_id,
                        $payload['shift_date'],
                        $safetyPermitReference,
                        $line,
                        $employee
                    );
                }

                $interval = null;
                if (! empty($entryPayload['start_at']) || ! empty($entryPayload['end_at'])) {
                    try {
                        $interval = WorkTimeInterval::fromInput(['work_date' => $payload['shift_date'], ...$entryPayload], (string) config('app.timezone', 'UTC'));
                    } catch (\Throwable $exception) {
                        throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_duration'));
                    }
                }
                app(WorkforcePeriodMutationGuard::class)->assertMutable($organizationId, (int) $workOrder->project_id, [
                    ...$entryPayload, ...($interval?->persistence() ?? []), 'work_date' => $payload['shift_date'], 'user_id' => $employee?->user_id ?? ($entryPayload['user_id'] ?? null), 'employee_id' => $employee?->id,
                ]);
                $hours = $interval ? $interval->netMinutes() / 60 : (float) $entryPayload['hours'];
                if (! is_finite($hours) || $hours <= 0 || $hours > 24) {
                    throw new DomainException(trans_message('time_tracking.mobile.errors.invalid_duration'));
                }
                if ($employee !== null) {
                    $this->assertEmployeeTimeAvailable($organizationId, (int) $employee->id, $payload['shift_date'], $hours, $interval);
                }
                if (! empty($entryPayload['user_id']) && ! User::query()->whereKey($entryPayload['user_id'])->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organizationId)->where('organization_user.is_active', true))->exists()) {
                    throw new DomainException(trans_message('time_tracking.mobile.errors.permission_denied'));
                }
                $metadata = $entryPayload['metadata'] ?? [];
                unset($metadata['time_interval'], $metadata['requires_reconciliation']);
                $metadata['time_interval'] = $interval?->persistence();
                $metadata['requires_reconciliation'] = $interval === null || $interval->requiresReconciliation;
                $timesheet->entries()->create([
                    'organization_id' => $organizationId,
                    'work_order_line_id' => $line->id,
                    'user_id' => $entryPayload['user_id'] ?? null,
                    'employee_id' => $employee?->id,
                    'include_in_payroll' => (bool) $includeInPayroll,
                    'worker_name' => $workerName !== '' ? $workerName : null,
                    'hours' => $hours,
                    'safety_permit_reference' => isset($entryPayload['safety_permit_reference'])
                        ? trim((string) $entryPayload['safety_permit_reference'])
                        : null,
                    'metadata' => $metadata,
                ]);
            }

            return $timesheet->load(['workOrder:id,order_number,title', 'entries.line:id,name,requires_safety_permit', 'entries.employee']);
        });
    }

    public function reports(int $organizationId, array $filters = []): array
    {
        $outputs = ProductionLaborOutputEntry::query()
            ->where('organization_id', $organizationId)
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId));
        $payroll = ProductionLaborPayrollAccrual::query()
            ->where('organization_id', $organizationId)
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId));

        return [
            'output_by_project' => (clone $outputs)
                ->selectRaw('project_id, SUM(quantity) as quantity, SUM(hours) as hours, COUNT(*) as count')
                ->groupBy('project_id')
                ->get(),
            'payroll_by_project' => (clone $payroll)
                ->selectRaw('project_id, SUM(amount) as amount, COUNT(*) as count')
                ->groupBy('project_id')
                ->get(),
        ];
    }

    public function findWorkOrder(int $organizationId, int $id): ProductionLaborWorkOrder
    {
        $workOrder = ProductionLaborWorkOrder::query()
            ->with(['project:id,name', 'lines'])
            ->where('organization_id', $organizationId)
            ->find($id);

        if (! $workOrder) {
            throw new DomainException(trans_message('production_labor.errors.work_order_not_found'));
        }

        return $workOrder;
    }

    private function findLine(int $organizationId, int $id): ProductionLaborWorkOrderLine
    {
        $line = ProductionLaborWorkOrderLine::query()
            ->with('workOrder')
            ->where('organization_id', $organizationId)
            ->find($id);

        if (! $line) {
            throw new DomainException(trans_message('production_labor.errors.line_not_found'));
        }

        return $line;
    }

    private function assertActorProject(int $organizationId, int $userId, int $projectId, mixed $expectedProject = null): void
    {
        $user = User::query()->find($userId);
        if (! $user || ($expectedProject !== null && (int) $expectedProject !== $projectId)
            || ! $user->organizations()->where('organizations.id', $organizationId)->wherePivot('is_active', true)->exists()
            || ! app(AuthorizationService::class)->can($user, 'production-labor.output.record', ['organization_id' => $organizationId, 'project_id' => $projectId])
            || ! app(UserProjectAccessService::class)->queryAccessibleProjects($user, $organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('production_labor.errors.project_not_found'), 403);
        }
    }

    /** Organization lock held by caller; checks span all work orders and projects. */
    private function assertEmployeeTimeAvailable(int $organizationId, int $employeeId, string $date, float $hours, ?WorkTimeInterval $interval): void
    {
        $entries = ProductionLaborTimesheetEntry::query()->with('timesheet')
            ->where('organization_id', $organizationId)->where('employee_id', $employeeId)
            ->whereHas('timesheet', fn ($query) => $query->where('status', '!=', 'cancelled')->whereBetween('shift_date', [
                CarbonImmutable::parse($date)->subDays(2)->toDateString(), CarbonImmutable::parse($date)->addDays(2)->toDateString(),
            ]))
            ->get();
        $dailyHours = $hours;
        foreach ($entries as $entry) {
            if ($entry->timesheet?->shift_date?->format('Y-m-d') === substr($date, 0, 10)) {
                $dailyHours += (float) $entry->hours;
            }
            $existing = $entry->metadata['time_interval'] ?? null;
            if ($interval === null || ! is_array($existing)) {
                continue;
            }
            try {
                $other = WorkTimeInterval::fromInput($existing, (string) config('app.timezone', 'UTC'));
            } catch (\Throwable $exception) {
                throw new DomainException(trans_message('production_labor.errors.employee_time_reconciliation_required'));
            }
            if ($interval->overlaps($other)) {
                throw new DomainException(trans_message('production_labor.errors.employee_time_overlap'), 409);
            }
        }
        if ($dailyHours > 24.000001) {
            throw new DomainException(trans_message('production_labor.errors.employee_time_overlap'), 409);
        }
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        $exists = Project::query()
            ->accessibleByOrganization($organizationId)
            ->whereKey($projectId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('production_labor.errors.project_not_found'));
        }
    }

    private function assertWorkOrderSourcesMutable(ProductionLaborWorkOrder $workOrder): void
    {
        $guard = app(WorkforcePeriodMutationGuard::class);
        foreach (ProductionLaborOutputEntry::query()->where('organization_id', $workOrder->organization_id)->where('work_order_id', $workOrder->id)->get() as $output) {
            $guard->assertMutable((int) $workOrder->organization_id, (int) $workOrder->project_id, [
                'work_date' => $output->work_date->format('Y-m-d'), 'user_id' => $output->recorded_by_user_id,
            ]);
        }
        foreach (ProductionLaborTimesheet::query()->where('organization_id', $workOrder->organization_id)->where('work_order_id', $workOrder->id)->with('entries.employee')->get() as $timesheet) {
            foreach ($timesheet->entries as $entry) {
                $guard->assertMutable((int) $workOrder->organization_id, (int) $workOrder->project_id, [
                    ...($entry->metadata['time_interval'] ?? []), 'work_date' => $timesheet->shift_date->format('Y-m-d'), 'user_id' => $entry->employee?->user_id ?? $entry->user_id, 'employee_id' => $entry->employee_id,
                ]);
            }
        }
    }

    private function assertPayrollPeriodOpenForWorkDate(int $organizationId, int $projectId, string $workDate): void
    {
        $isLocked = DB::table('workforce_payroll_periods')
            ->where('organization_id', $organizationId)
            ->where('status', 'locked')
            ->whereDate('period_start', '<=', $workDate)
            ->whereDate('period_end', '>=', $workDate)
            ->where(function (Builder $query) use ($projectId): void {
                $query->whereNull('project_id')
                    ->orWhere('project_id', $projectId);
            })
            ->exists();

        if ($isLocked) {
            throw new DomainException(trans_message('production_labor.errors.payroll_period_locked'));
        }
    }

    private function lockOrganization(int $organizationId): void
    {
        Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertActiveSafetyPermit(
        int $organizationId,
        int $projectId,
        string $shiftDate,
        string $permitNumber,
        ProductionLaborWorkOrderLine $line,
        ?WorkforceEmployee $employee = null
    ): void {
        $permit = SafetyWorkPermit::query()
            ->with('participants')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('permit_number', $permitNumber)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $shiftDate)
            ->whereDate('valid_until', '>=', $shiftDate)
            ->first();

        if (! $permit instanceof SafetyWorkPermit) {
            throw new DomainException(trans_message('production_labor.errors.safety_permit_required'));
        }

        if ($employee === null) {
            return;
        }

        if ($permit->participants->isEmpty()) {
            throw new DomainException(trans_message('production_labor.errors.safety_permit_employee_not_admitted'));
        }

        $participant = $permit->participants->firstWhere('employee_id', $employee->id);

        if ($participant === null) {
            throw new DomainException(trans_message('production_labor.errors.safety_permit_employee_not_admitted'));
        }

        $metadata = is_array($line->metadata) ? $line->metadata : [];
        $result = $this->safetyComplianceService->check(new SafetyComplianceContext(
            organizationId: $organizationId,
            employeeId: (int) $employee->id,
            userId: $employee->user_id === null ? null : (int) $employee->user_id,
            projectId: $projectId,
            workTypeId: $line->work_type_id === null ? null : (int) $line->work_type_id,
            workCategory: (string) ($metadata['work_category'] ?? $metadata['safety_work_category'] ?? $participant->work_category ?? $permit->permit_type),
            date: CarbonImmutable::parse($shiftDate),
            positionName: $participant->position_name,
            permitId: (int) $permit->id,
            workOrderLineId: (int) $line->id,
        ));

        if ($result->blocked) {
            throw new DomainException(trans_message('production_labor.errors.safety_permit_employee_not_admitted'));
        }
    }

    private function resolveTimesheetEmployee(
        int $organizationId,
        mixed $employeeId,
        string $shiftDate,
        bool $includeInPayroll
    ): ?WorkforceEmployee {
        if (! $includeInPayroll) {
            return null;
        }

        if ($employeeId === null || $employeeId === '') {
            throw new DomainException(trans_message('production_labor.errors.employee_required_for_payroll'));
        }

        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->find((int) $employeeId);

        if (! $employee) {
            throw new DomainException(trans_message('production_labor.errors.employee_not_found'));
        }

        if ($employee->employment_status === 'inactive') {
            throw new DomainException(trans_message('production_labor.errors.employee_inactive_for_shift'));
        }

        if ($employee->employment_status === 'dismissed' && $employee->dismissal_date !== null && $employee->dismissal_date->lt($shiftDate)) {
            throw new DomainException(trans_message('production_labor.errors.employee_inactive_for_shift'));
        }

        if ($employee->hire_date->gt($shiftDate)) {
            throw new DomainException(trans_message('production_labor.errors.employee_inactive_for_shift'));
        }

        return $employee;
    }
}
