<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Services;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ProductionLaborService
{
    public function paginateWorkOrders(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ProductionLaborWorkOrder::query()
            ->with(['project:id,name', 'lines'])
            ->where('organization_id', $organizationId)
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
        if ($workOrder->status !== 'draft') {
            throw new DomainException(trans_message('production_labor.errors.issue_invalid_status'));
        }

        $workOrder->update(['status' => 'issued', 'issued_at' => now()]);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function startWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        if ($workOrder->status !== 'issued') {
            throw new DomainException(trans_message('production_labor.errors.start_invalid_status'));
        }

        $workOrder->update(['status' => 'in_progress']);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function submitWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        if (!in_array($workOrder->status, ['issued', 'in_progress'], true)) {
            throw new DomainException(trans_message('production_labor.errors.submit_invalid_status'));
        }

        $workOrder->update(['status' => 'submitted', 'submitted_at' => now()]);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function acceptWorkOrder(ProductionLaborWorkOrder $workOrder, int $userId): ProductionLaborWorkOrder
    {
        if ($workOrder->status !== 'submitted') {
            throw new DomainException(trans_message('production_labor.errors.accept_invalid_status'));
        }

        $workOrder->update([
            'status' => 'accepted',
            'accepted_by_user_id' => $userId,
            'accepted_at' => now(),
        ]);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function returnWorkOrder(ProductionLaborWorkOrder $workOrder, string $reason): ProductionLaborWorkOrder
    {
        if ($workOrder->status !== 'submitted') {
            throw new DomainException(trans_message('production_labor.errors.return_invalid_status'));
        }

        $workOrder->update(['status' => 'returned', 'return_reason' => $reason]);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function closeWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        if ($workOrder->status !== 'accepted') {
            throw new DomainException(trans_message('production_labor.errors.close_invalid_status'));
        }

        $workOrder->update(['status' => 'closed', 'closed_at' => now()]);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function cancelWorkOrder(ProductionLaborWorkOrder $workOrder): ProductionLaborWorkOrder
    {
        if (!in_array($workOrder->status, ['issued', 'in_progress'], true)) {
            throw new DomainException(trans_message('production_labor.errors.cancel_invalid_status'));
        }

        $workOrder->update(['status' => 'cancelled']);

        return $workOrder->refresh()->load(['project:id,name', 'lines']);
    }

    public function recordOutput(int $organizationId, int $userId, array $payload, bool $allowOverrun = false): ProductionLaborOutputEntry
    {
        $line = $this->findLine($organizationId, (int) $payload['work_order_line_id']);
        $workOrder = $line->workOrder;

        if (!in_array($workOrder->status, ['issued', 'in_progress'], true)) {
            throw new DomainException(trans_message('production_labor.errors.output_invalid_status'));
        }

        $quantity = (float) $payload['quantity'];
        $accepted = (float) $line->accepted_quantity;
        $planned = (float) $line->planned_quantity;

        if (!$allowOverrun && $planned > 0 && round($accepted + $quantity, 4) > $planned) {
            throw new DomainException(trans_message('production_labor.errors.output_over_plan'));
        }

        return DB::transaction(function () use ($organizationId, $userId, $payload, $line, $workOrder): ProductionLaborOutputEntry {
            $entry = ProductionLaborOutputEntry::create([
                'organization_id' => $organizationId,
                'work_order_id' => $workOrder->id,
                'work_order_line_id' => $line->id,
                'project_id' => $workOrder->project_id,
                'schedule_task_id' => $line->schedule_task_id ?? $workOrder->schedule_task_id,
                'recorded_by_user_id' => $userId,
                'work_date' => $payload['work_date'],
                'quantity' => $payload['quantity'],
                'hours' => $payload['hours'] ?? 0,
                'status' => 'accepted',
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'comment' => $payload['comment'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $line->update(['accepted_quantity' => (float) $line->accepted_quantity + (float) $payload['quantity']]);

            return $entry->load(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name']);
        });
    }

    public function createTimesheet(int $organizationId, int $userId, array $payload): ProductionLaborTimesheet
    {
        $workOrder = $this->findWorkOrder($organizationId, (int) $payload['work_order_id']);

        if (!in_array($workOrder->status, ['issued', 'in_progress'], true)) {
            throw new DomainException(trans_message('production_labor.errors.timesheet_invalid_status'));
        }

        return DB::transaction(function () use ($organizationId, $userId, $payload, $workOrder): ProductionLaborTimesheet {
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

                if ((bool) $includeInPayroll && !empty($entryPayload['worker_name'])) {
                    throw new DomainException(trans_message('production_labor.errors.worker_name_not_allowed_for_payroll'));
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

                if ($line->requires_safety_permit && empty($entryPayload['safety_permit_reference'])) {
                    throw new DomainException(trans_message('production_labor.errors.safety_permit_required'));
                }

                $timesheet->entries()->create([
                    'organization_id' => $organizationId,
                    'work_order_line_id' => $line->id,
                    'user_id' => $entryPayload['user_id'] ?? null,
                    'employee_id' => $employee?->id,
                    'include_in_payroll' => (bool) $includeInPayroll,
                    'worker_name' => $entryPayload['worker_name'] ?? null,
                    'hours' => $entryPayload['hours'],
                    'safety_permit_reference' => $entryPayload['safety_permit_reference'] ?? null,
                    'metadata' => $entryPayload['metadata'] ?? null,
                ]);
            }

            return $timesheet->load(['workOrder:id,order_number,title', 'entries.line:id,name,requires_safety_permit', 'entries.employee']);
        });
    }

    public function preparePayroll(int $organizationId, int $userId, array $payload): Collection
    {
        $workOrder = $this->findWorkOrder($organizationId, (int) $payload['work_order_id']);

        if (!in_array($workOrder->status, ['accepted', 'closed'], true)) {
            throw new DomainException(trans_message('production_labor.errors.payroll_invalid_status'));
        }

        return DB::transaction(function () use ($organizationId, $userId, $workOrder, $payload): Collection {
            $created = new Collection();

            foreach ($workOrder->lines as $line) {
                $exists = ProductionLaborPayrollAccrual::query()
                    ->where('organization_id', $organizationId)
                    ->where('work_order_line_id', $line->id)
                    ->whereDate('period_start', $payload['period_start'])
                    ->whereDate('period_end', $payload['period_end'])
                    ->exists();

                if ($exists) {
                    throw new DomainException(trans_message('production_labor.errors.payroll_duplicate'));
                }

                $hours = (float) ProductionLaborOutputEntry::query()
                    ->where('organization_id', $organizationId)
                    ->where('work_order_line_id', $line->id)
                    ->whereBetween('work_date', [$payload['period_start'], $payload['period_end']])
                    ->where('status', 'accepted')
                    ->sum('hours');
                $quantity = (float) $line->accepted_quantity;
                $amount = $line->pay_basis === 'hours'
                    ? $hours * (float) $line->hour_rate
                    : $quantity * (float) $line->unit_rate;

                $created->push(ProductionLaborPayrollAccrual::create([
                    'organization_id' => $organizationId,
                    'work_order_id' => $workOrder->id,
                    'work_order_line_id' => $line->id,
                    'project_id' => $workOrder->project_id,
                    'schedule_task_id' => $line->schedule_task_id ?? $workOrder->schedule_task_id,
                    'period_start' => $payload['period_start'],
                    'period_end' => $payload['period_end'],
                    'accepted_quantity' => $quantity,
                    'accepted_hours' => $hours,
                    'amount' => round($amount, 2),
                    'status' => 'prepared',
                    'approved_at' => now(),
                    'approved_by_user_id' => $userId,
                    'payment_payload' => [
                        'source' => 'production-labor',
                        'work_order_id' => $workOrder->id,
                        'work_order_line_id' => $line->id,
                        'project_id' => $workOrder->project_id,
                        'schedule_task_id' => $line->schedule_task_id ?? $workOrder->schedule_task_id,
                        'period_start' => $payload['period_start'],
                        'period_end' => $payload['period_end'],
                        'amount' => round($amount, 2),
                    ],
                ])->load(['workOrder:id,order_number,title', 'line:id,name,unit', 'project:id,name']));
            }

            return $created;
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

        if (!$workOrder) {
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

        if (!$line) {
            throw new DomainException(trans_message('production_labor.errors.line_not_found'));
        }

        return $line;
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        $exists = Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('production_labor.errors.project_not_found'));
        }
    }

    private function resolveTimesheetEmployee(
        int $organizationId,
        mixed $employeeId,
        string $shiftDate,
        bool $includeInPayroll
    ): ?WorkforceEmployee {
        if (!$includeInPayroll) {
            return null;
        }

        if ($employeeId === null || $employeeId === '') {
            throw new DomainException(trans_message('production_labor.errors.employee_required_for_payroll'));
        }

        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->find((int) $employeeId);

        if (!$employee) {
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
