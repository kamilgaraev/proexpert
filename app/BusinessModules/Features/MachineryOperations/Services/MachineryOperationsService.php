<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\Models\Machinery;
use App\Models\Project;
use App\Models\ScheduleTask;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MachineryOperationsService
{
    private const ASSET_RELATIONS = ['machinery:id,name,code,category', 'currentProject:id,name', 'currentScheduleTask:id,name'];
    private const SHIFT_RELATIONS = ['asset:id,name,asset_code,status', 'project:id,name', 'assignment:id,status'];

    public function paginateAssets(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return MachineryAsset::forOrganization($organizationId)
            ->with(self::ASSET_RELATIONS)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('current_project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function paginateShifts(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return MachineryShiftReport::forOrganization($organizationId)
            ->with(self::SHIFT_RELATIONS)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['asset_id']), fn ($query) => $query->where('asset_id', (int) $filters['asset_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('report_date')
            ->paginate($perPage);
    }

    public function paginateMaintenanceOrders(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return MachineryMaintenanceOrder::forOrganization($organizationId)
            ->with(['asset:id,name,asset_code,status', 'project:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['asset_id']), fn ($query) => $query->where('asset_id', (int) $filters['asset_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function createAsset(int $organizationId, array $data): MachineryAsset
    {
        $this->assertOptionalMachineryBelongsToOrganization($data['machinery_id'] ?? null, $organizationId);
        $this->assertOptionalProjectBelongsToOrganization($data['current_project_id'] ?? null, $organizationId);
        $this->assertOptionalScheduleTaskBelongsToOrganization($data['current_schedule_task_id'] ?? null, $organizationId);

        return MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'machinery_id' => $data['machinery_id'] ?? null,
            'current_project_id' => $data['current_project_id'] ?? null,
            'current_schedule_task_id' => $data['current_schedule_task_id'] ?? null,
            'asset_code' => $data['asset_code'],
            'name' => $data['name'],
            'inventory_number' => $data['inventory_number'] ?? null,
            'ownership_type' => $data['ownership_type'] ?? 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => $data['operating_cost_per_hour'] ?? 0,
            'fuel_type' => $data['fuel_type'] ?? null,
            'fuel_consumption_rate' => $data['fuel_consumption_rate'] ?? null,
            'meter_hours' => $data['meter_hours'] ?? 0,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(self::ASSET_RELATIONS);
    }

    public function findAsset(int $organizationId, int $id): ?MachineryAsset
    {
        return MachineryAsset::forOrganization($organizationId)
            ->with(self::ASSET_RELATIONS)
            ->find($id);
    }

    public function assignAsset(MachineryAsset $asset, int $userId, array $data): MachineryAssignment
    {
        if (!in_array($asset->status, ['available', 'assigned'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_assign_invalid_status'));
        }

        $this->assertProjectBelongsToOrganization((int) $data['project_id'], (int) $asset->organization_id);
        $this->assertOptionalScheduleTaskBelongsToOrganization($data['schedule_task_id'] ?? null, (int) $asset->organization_id);

        return DB::transaction(function () use ($asset, $userId, $data): MachineryAssignment {
            $assignment = MachineryAssignment::query()->create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'project_id' => (int) $data['project_id'],
                'schedule_task_id' => $data['schedule_task_id'] ?? null,
                'requested_by_user_id' => $userId,
                'approved_by_user_id' => $userId,
                'status' => 'active',
                'planned_start_at' => $data['planned_start_at'],
                'planned_end_at' => $data['planned_end_at'] ?? null,
                'actual_start_at' => now(),
                'planned_hours' => $data['planned_hours'] ?? null,
                'comment' => $data['comment'] ?? null,
            ]);

            $asset->update([
                'status' => 'assigned',
                'current_project_id' => (int) $data['project_id'],
                'current_schedule_task_id' => $data['schedule_task_id'] ?? null,
            ]);

            return $assignment->fresh(['asset', 'project', 'scheduleTask']);
        });
    }

    public function startOperation(MachineryAsset $asset): MachineryAsset
    {
        if ($asset->status !== 'assigned') {
            throw new DomainException(trans_message('machinery_operations.errors.asset_start_invalid_status'));
        }

        $asset->update(['status' => 'in_operation']);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function setMaintenance(MachineryAsset $asset): MachineryAsset
    {
        if (!in_array($asset->status, ['available', 'assigned', 'in_operation', 'unavailable'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_maintenance_invalid_status'));
        }

        $asset->update(['status' => 'maintenance']);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function setUnavailable(MachineryAsset $asset): MachineryAsset
    {
        if ($asset->status === 'archived') {
            throw new DomainException(trans_message('machinery_operations.errors.asset_unavailable_invalid_status'));
        }

        $asset->update(['status' => 'unavailable']);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function returnAvailable(MachineryAsset $asset): MachineryAsset
    {
        if (!in_array($asset->status, ['assigned', 'in_operation', 'maintenance', 'unavailable'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_available_invalid_status'));
        }

        $asset->update([
            'status' => 'available',
            'current_project_id' => null,
            'current_schedule_task_id' => null,
        ]);

        MachineryAssignment::forOrganization((int) $asset->organization_id)
            ->where('asset_id', $asset->id)
            ->where('status', 'active')
            ->update(['status' => 'completed', 'actual_end_at' => now()]);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function archiveAsset(MachineryAsset $asset): MachineryAsset
    {
        if (in_array($asset->status, ['assigned', 'in_operation'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_archive_invalid_status'));
        }

        $asset->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function createShiftReport(int $organizationId, int $userId, array $data): MachineryShiftReport
    {
        $asset = $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalAssignmentBelongsToOrganization($data['assignment_id'] ?? null, $organizationId);

        if (!in_array($asset->status, ['assigned', 'in_operation'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.shift_asset_not_operational'));
        }

        return MachineryShiftReport::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $asset->id,
            'project_id' => (int) $data['project_id'],
            'assignment_id' => $data['assignment_id'] ?? null,
            'reported_by_user_id' => $userId,
            'report_date' => $data['report_date'],
            'status' => 'draft',
            'planned_hours' => $data['planned_hours'] ?? $data['actual_hours'],
            'actual_hours' => $data['actual_hours'],
            'fuel_consumed' => $data['fuel_consumed'],
            'meter_start' => $data['meter_start'] ?? null,
            'meter_end' => $data['meter_end'] ?? null,
            'work_description' => $data['work_description'] ?? null,
        ])->fresh(self::SHIFT_RELATIONS);
    }

    public function findShift(int $organizationId, int $id): ?MachineryShiftReport
    {
        return MachineryShiftReport::forOrganization($organizationId)
            ->with(self::SHIFT_RELATIONS)
            ->find($id);
    }

    public function submitShift(MachineryShiftReport $shift): MachineryShiftReport
    {
        if ($shift->status !== 'draft') {
            throw new DomainException(trans_message('machinery_operations.errors.shift_submit_invalid_status'));
        }

        $shift->update(['status' => 'submitted', 'submitted_at' => now()]);

        return $shift->fresh(self::SHIFT_RELATIONS);
    }

    public function approveShift(MachineryShiftReport $shift, int $userId): MachineryShiftReport
    {
        if ($shift->status !== 'submitted') {
            throw new DomainException(trans_message('machinery_operations.errors.shift_approve_invalid_status'));
        }

        return DB::transaction(function () use ($shift, $userId): MachineryShiftReport {
            $shift->update([
                'status' => 'approved',
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            if ($shift->meter_end !== null) {
                $shift->asset()->update(['meter_hours' => $shift->meter_end]);
            }

            return $shift->fresh(self::SHIFT_RELATIONS);
        });
    }

    public function rejectShift(MachineryShiftReport $shift, int $userId, string $reason): MachineryShiftReport
    {
        if ($shift->status !== 'submitted') {
            throw new DomainException(trans_message('machinery_operations.errors.shift_reject_invalid_status'));
        }

        $shift->update([
            'status' => 'rejected',
            'approved_by_user_id' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => trim($reason),
        ]);

        return $shift->fresh(self::SHIFT_RELATIONS);
    }

    public function createDowntime(int $organizationId, array $data): MachineryDowntime
    {
        $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalShiftBelongsToOrganization($data['shift_report_id'] ?? null, $organizationId);

        return MachineryDowntime::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => (int) $data['asset_id'],
            'project_id' => (int) $data['project_id'],
            'shift_report_id' => $data['shift_report_id'] ?? null,
            'reason' => $data['reason'],
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'],
            'comment' => $data['comment'] ?? null,
        ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
    }

    public function createProductionRecord(int $organizationId, int $userId, array $data): MachineryProductionRecord
    {
        $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalShiftBelongsToOrganization($data['shift_report_id'] ?? null, $organizationId);

        return MachineryProductionRecord::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => (int) $data['asset_id'],
            'project_id' => (int) $data['project_id'],
            'shift_report_id' => $data['shift_report_id'] ?? null,
            'recorded_by_user_id' => $userId,
            'recorded_at' => $data['recorded_at'],
            'quantity' => $data['quantity'],
            'unit' => $data['unit'],
            'comment' => $data['comment'] ?? null,
        ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
    }

    public function createFuelIssue(int $organizationId, int $userId, array $data): MachineryFuelIssue
    {
        $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);

        return MachineryFuelIssue::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => (int) $data['asset_id'],
            'project_id' => (int) $data['project_id'],
            'issued_by_user_id' => $userId,
            'issued_at' => $data['issued_at'],
            'fuel_type' => $data['fuel_type'],
            'quantity' => $data['quantity'],
            'unit' => $data['unit'],
            'cost' => $data['cost'] ?? 0,
            'comment' => $data['comment'] ?? null,
        ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
    }

    public function createMaintenanceOrder(int $organizationId, int $userId, array $data): MachineryMaintenanceOrder
    {
        $asset = $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data, $asset): MachineryMaintenanceOrder {
            $order = MachineryMaintenanceOrder::query()->create([
                'organization_id' => $organizationId,
                'asset_id' => $asset->id,
                'project_id' => $data['project_id'] ?? $asset->current_project_id,
                'requested_by_user_id' => $userId,
                'order_number' => $this->nextNumber('MO', $organizationId),
                'title' => $data['title'],
                'maintenance_type' => $data['maintenance_type'] ?? 'repair',
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'description' => $data['description'] ?? null,
                'planned_at' => $data['planned_at'] ?? null,
                'cost' => $data['cost'] ?? 0,
            ]);

            $asset->update(['status' => 'maintenance']);

            return $order->fresh(['asset:id,name,asset_code,status', 'project:id,name']);
        });
    }

    public function completeMaintenanceOrder(MachineryMaintenanceOrder $order, int $userId, ?string $comment): MachineryMaintenanceOrder
    {
        if (!in_array($order->status, ['open', 'in_progress'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.maintenance_complete_invalid_status'));
        }

        return DB::transaction(function () use ($order, $userId, $comment): MachineryMaintenanceOrder {
            $order->update([
                'status' => 'completed',
                'completed_by_user_id' => $userId,
                'completed_at' => now(),
                'completion_comment' => $comment,
            ]);

            $order->asset()->update(['status' => 'available']);

            return $order->fresh(['asset:id,name,asset_code,status', 'project:id,name']);
        });
    }

    public function findMaintenanceOrder(int $organizationId, int $id): ?MachineryMaintenanceOrder
    {
        return MachineryMaintenanceOrder::forOrganization($organizationId)
            ->with(['asset:id,name,asset_code,status', 'project:id,name'])
            ->find($id);
    }

    public function reports(int $organizationId, array $filters = []): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;

        $shifts = MachineryShiftReport::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId));
        $downtimes = MachineryDowntime::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId));
        $fuel = MachineryFuelIssue::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId));

        return [
            'utilization_by_project' => MachineryShiftReport::forOrganization($organizationId)
                ->selectRaw('project_id, sum(actual_hours) as actual_hours, sum(planned_hours) as planned_hours')
                ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
                ->groupBy('project_id')
                ->get()
                ->all(),
            'downtime_by_reason' => $downtimes
                ->selectRaw('reason, sum(duration_minutes) as duration_minutes, count(*) as count')
                ->groupBy('reason')
                ->get()
                ->all(),
            'fuel_consumption' => $fuel
                ->selectRaw('fuel_type, sum(quantity) as quantity, sum(cost) as cost')
                ->groupBy('fuel_type')
                ->get()
                ->all(),
            'operating_cost_by_project' => MachineryShiftReport::query()
                ->join('machinery_assets', 'machinery_shift_reports.asset_id', '=', 'machinery_assets.id')
                ->selectRaw('machinery_shift_reports.project_id, sum(machinery_shift_reports.actual_hours * machinery_assets.operating_cost_per_hour) as cost')
                ->where('machinery_shift_reports.organization_id', $organizationId)
                ->when($projectId !== null, fn ($query) => $query->where('machinery_shift_reports.project_id', $projectId))
                ->whereNull('machinery_shift_reports.deleted_at')
                ->groupBy('machinery_shift_reports.project_id')
                ->get()
                ->all(),
            'plan_fact_variance' => MachineryShiftReport::forOrganization($organizationId)
                ->selectRaw('project_id, sum(planned_hours) as planned_hours, sum(actual_hours) as actual_hours, sum(actual_hours - planned_hours) as variance_hours')
                ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
                ->groupBy('project_id')
                ->get()
                ->all(),
        ];
    }

    private function requireAsset(int $id, int $organizationId): MachineryAsset
    {
        $asset = $this->findAsset($organizationId, $id);

        if ($asset === null) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_not_found'));
        }

        return $asset;
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        if (!Project::query()->where('id', $projectId)->where('organization_id', $organizationId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.project_not_found'));
        }
    }

    private function assertOptionalProjectBelongsToOrganization(mixed $projectId, int $organizationId): void
    {
        if ($projectId !== null) {
            $this->assertProjectBelongsToOrganization((int) $projectId, $organizationId);
        }
    }

    private function assertOptionalMachineryBelongsToOrganization(mixed $machineryId, int $organizationId): void
    {
        if ($machineryId === null) {
            return;
        }

        $exists = Machinery::query()
            ->where('id', (int) $machineryId)
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
            })
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('machinery_operations.errors.machinery_not_found'));
        }
    }

    private function assertOptionalScheduleTaskBelongsToOrganization(mixed $scheduleTaskId, int $organizationId): void
    {
        if ($scheduleTaskId === null) {
            return;
        }

        if (!ScheduleTask::query()->where('id', (int) $scheduleTaskId)->where('organization_id', $organizationId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.schedule_task_not_found'));
        }
    }

    private function assertOptionalAssignmentBelongsToOrganization(mixed $assignmentId, int $organizationId): void
    {
        if ($assignmentId !== null && !MachineryAssignment::forOrganization($organizationId)->where('id', (int) $assignmentId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.assignment_not_found'));
        }
    }

    private function assertOptionalShiftBelongsToOrganization(mixed $shiftId, int $organizationId): void
    {
        if ($shiftId !== null && !MachineryShiftReport::forOrganization($organizationId)->where('id', (int) $shiftId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.shift_not_found'));
        }
    }

    private function nextNumber(string $prefix, int $organizationId): string
    {
        return sprintf('%s-%d-%s', $prefix, $organizationId, now()->format('YmdHisv'));
    }
}
