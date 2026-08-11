<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
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

    public function __construct(
        private readonly MachineryAssetReadRepository $assets,
        private readonly OrganizationAssetService $organizationAssets,
        private readonly MachineryWorkflowPolicy $workflow,
    ) {}

    public function paginateAssets(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return $this->assets->paginate($organizationId, $perPage, $filters);
    }

    public function paginateShifts(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return MachineryShiftReport::forOrganization($organizationId)
            ->with(self::SHIFT_RELATIONS)
            ->when(array_key_exists('project_ids', $filters), fn ($query) => $query->whereIn('project_id', $filters['project_ids']))
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(! empty($filters['asset_id']), fn ($query) => $query->where('asset_id', (int) $filters['asset_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('report_date')
            ->paginate($perPage);
    }

    public function paginateMaintenanceOrders(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return MachineryMaintenanceOrder::forOrganization($organizationId)
            ->with(['asset:id,name,asset_code,status', 'project:id,name'])
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(! empty($filters['asset_id']), fn ($query) => $query->where('asset_id', (int) $filters['asset_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function createAsset(int $organizationId, array $data): MachineryAsset
    {
        $this->assertOptionalMachineryBelongsToOrganization($data['machinery_id'] ?? null, $organizationId);
        $this->assertOptionalProjectBelongsToOrganization($data['current_project_id'] ?? null, $organizationId);
        $this->assertOptionalScheduleTaskBelongsToOrganization($data['current_schedule_task_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $data): MachineryAsset {
            $initialStatus = isset($data['current_project_id']) ? 'assigned' : 'available';
            $legacy = MachineryAsset::query()->create([
                'organization_id' => $organizationId,
                'machinery_id' => $data['machinery_id'] ?? null,
                'current_project_id' => $data['current_project_id'] ?? null,
                'current_schedule_task_id' => $data['current_schedule_task_id'] ?? null,
                'asset_code' => $data['asset_code'],
                'name' => $data['name'],
                'inventory_number' => $data['inventory_number'] ?? null,
                'ownership_type' => $data['ownership_type'] ?? 'owned',
                'status' => $initialStatus,
                'operating_cost_per_hour' => $data['operating_cost_per_hour'] ?? 0,
                'fuel_type' => $data['fuel_type'] ?? null,
                'fuel_consumption_rate' => $data['fuel_consumption_rate'] ?? null,
                'meter_hours' => $data['meter_hours'] ?? 0,
                'metadata' => $data['metadata'] ?? null,
            ]);
            $canonical = $this->organizationAssets->create($organizationId, new CreateOrganizationAssetData(
                name: (string) $legacy->name,
                inventoryNumber: (string) ($legacy->inventory_number ?: $legacy->asset_code),
                ownershipType: (string) $legacy->ownership_type,
                machineryId: $legacy->machinery_id !== null ? (int) $legacy->machinery_id : null,
                placement: $legacy->current_project_id !== null
                    ? new AssetPlacementData(projectId: (int) $legacy->current_project_id)
                    : null,
                metadata: [
                    'legacy_source' => ['table' => 'machinery_assets', 'id' => (int) $legacy->id],
                    'machinery_operation_status' => $initialStatus,
                ],
                operationalMode: AssetOperationalMode::ShiftOperation,
                tracksMeter: true,
                tracksFuel: $legacy->fuel_type !== null || $legacy->fuel_consumption_rate !== null,
                tracksProduction: true,
                maintenanceEnabled: true,
                meterUnit: 'hour',
            ));
            $legacy->update(['organization_asset_id' => $canonical->id]);

            return $this->assets->find($organizationId, (int) $legacy->id) ?? $legacy;
        });
    }

    public function findAsset(int $organizationId, int $id): ?MachineryAsset
    {
        return $this->assets->find($organizationId, $id);
    }

    /** @return array<string, mixed>|null */
    public function assetWorkspace(int $organizationId, int $id): ?array
    {
        $asset = $this->findAsset($organizationId, $id);
        if ($asset === null) {
            return null;
        }

        return [
            'asset' => $asset,
            'assignments' => MachineryAssignment::forOrganization($organizationId)->where('asset_id', $id)->with('project:id,name')->latest()->limit(50)->get(),
            'shifts' => MachineryShiftReport::forOrganization($organizationId)->where('asset_id', $id)->with('project:id,name')->latest('report_date')->limit(50)->get(),
            'fuel_issues' => MachineryFuelIssue::forOrganization($organizationId)->where('asset_id', $id)->latest('issued_at')->limit(50)->get(),
            'maintenance_orders' => MachineryMaintenanceOrder::forOrganization($organizationId)->where('asset_id', $id)->latest()->limit(50)->get(),
            'custody_history' => $asset->organizationAsset?->custodyEvents()->latest('occurred_at')->limit(100)->get() ?? [],
            'documents' => [],
            'costs' => [
                'fuel' => (float) MachineryFuelIssue::forOrganization($organizationId)->where('asset_id', $id)->sum('cost'),
                'maintenance' => (float) MachineryMaintenanceOrder::forOrganization($organizationId)->where('asset_id', $id)->sum('cost'),
            ],
        ];
    }

    public function assignAsset(MachineryAsset $asset, int $userId, array $data): MachineryAssignment
    {
        return DB::transaction(function () use ($asset, $userId, $data): MachineryAssignment {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);

            if (! in_array($this->workflow->status($lockedAsset), ['available', 'assigned'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_assign_invalid_status'));
            }

            $this->assertProjectBelongsToOrganization(
                (int) $data['project_id'],
                (int) $lockedAsset->organization_id,
            );
            $this->assertOptionalScheduleTaskBelongsToOrganization(
                $data['schedule_task_id'] ?? null,
                (int) $lockedAsset->organization_id,
            );
            $this->assertNoOverlappingActiveAssignment(
                $lockedAsset,
                $data['planned_start_at'],
                $data['planned_end_at'] ?? null,
            );

            $assignment = MachineryAssignment::query()->create([
                'organization_id' => $lockedAsset->organization_id,
                'organization_asset_id' => $lockedAsset->organization_asset_id,
                'asset_id' => $lockedAsset->id,
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

            $lockedAsset->update([
                'status' => 'assigned',
                'current_project_id' => (int) $data['project_id'],
                'current_schedule_task_id' => $data['schedule_task_id'] ?? null,
            ]);
            $this->moveCanonicalToProject($lockedAsset, (int) $data['project_id'], $userId, 'assigned');

            return $assignment->fresh(['asset', 'project', 'scheduleTask']);
        });
    }

    public function startOperation(MachineryAsset $asset): MachineryAsset
    {
        if ($this->workflow->status($asset) !== 'assigned') {
            throw new DomainException(trans_message('machinery_operations.errors.asset_start_invalid_status'));
        }

        $asset->update(['status' => 'in_operation']);
        $this->updateCanonicalState($asset, operationStatus: 'in_operation');

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function setMaintenance(MachineryAsset $asset): MachineryAsset
    {
        if (! in_array($this->workflow->status($asset), ['available', 'assigned', 'in_operation', 'unavailable'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_maintenance_invalid_status'));
        }

        $asset->update(['status' => 'maintenance']);
        $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Maintenance);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function setUnavailable(MachineryAsset $asset): MachineryAsset
    {
        if ($this->workflow->status($asset) === 'archived') {
            throw new DomainException(trans_message('machinery_operations.errors.asset_unavailable_invalid_status'));
        }

        $asset->update(['status' => 'unavailable']);
        $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Unavailable);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function returnAvailable(MachineryAsset $asset): MachineryAsset
    {
        if (! in_array($this->workflow->status($asset), ['assigned', 'in_operation', 'maintenance', 'unavailable'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_available_invalid_status'));
        }

        $asset->update([
            'status' => 'available',
            'current_project_id' => null,
            'current_schedule_task_id' => null,
        ]);
        $this->updateCanonicalState(
            $asset,
            operationStatus: 'available',
            technicalStatus: AssetTechnicalStatus::Serviceable,
            clearPlacement: true,
        );

        MachineryAssignment::forOrganization((int) $asset->organization_id)
            ->where('asset_id', $asset->id)
            ->where('status', 'active')
            ->update(['status' => 'completed', 'actual_end_at' => now()]);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function archiveAsset(MachineryAsset $asset): MachineryAsset
    {
        if (in_array($this->workflow->status($asset), ['assigned', 'in_operation'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_archive_invalid_status'));
        }

        $asset->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);
        $this->updateCanonicalState($asset, lifecycleStatus: AssetLifecycleStatus::Retired);

        return $asset->fresh(self::ASSET_RELATIONS);
    }

    public function createShiftReport(int $organizationId, int $userId, array $data): MachineryShiftReport
    {
        return DB::transaction(function () use ($organizationId, $userId, $data): MachineryShiftReport {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $projectId = (int) $data['project_id'];
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
            $this->assertAssetProjectConsistency($asset, $projectId);
            $this->assertAssignmentLinkConsistency(
                $data['assignment_id'] ?? null,
                $organizationId,
                (int) $asset->id,
                $projectId,
            );

            if (! in_array($this->workflow->status($asset), ['assigned', 'in_operation'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.shift_asset_not_operational'));
            }

            return MachineryShiftReport::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
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
        });
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

    public function finishShift(MachineryShiftReport $shift, array $data): MachineryShiftReport
    {
        if ($shift->status !== 'draft') {
            throw new DomainException(trans_message('machinery_operations.errors.shift_finish_invalid_status'));
        }
        if ($shift->meter_start !== null && isset($data['meter_end']) && (float) $data['meter_end'] < (float) $shift->meter_start) {
            throw new DomainException(trans_message('machinery_operations.errors.meter_end_before_start'));
        }

        $shift->update([
            'actual_hours' => $data['actual_hours'],
            'fuel_consumed' => $data['fuel_consumed'],
            'meter_end' => $data['meter_end'] ?? $shift->meter_end,
            'work_description' => $data['work_description'] ?? $shift->work_description,
        ]);

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
                $asset = $shift->asset()->first();
                if ($asset !== null) {
                    $canonical = $this->canonicalAsset($asset, true);
                    if ($canonical !== null) {
                        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
                        $metadata['meter_hours'] = (float) $shift->meter_end;
                        $canonical->update(['metadata' => $metadata]);
                    }
                }
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
        return DB::transaction(function () use ($organizationId, $data): MachineryDowntime {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $projectId = (int) $data['project_id'];
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
            $this->assertAssetProjectConsistency($asset, $projectId);
            $shift = $this->findOptionalShift($data['shift_report_id'] ?? null, $organizationId);

            if ($shift !== null) {
                $this->assertShiftLinkConsistency($shift, (int) $asset->id, $projectId);
            }

            return MachineryDowntime::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'shift_report_id' => $data['shift_report_id'] ?? null,
                'reason' => $data['reason'],
                'started_at' => $data['started_at'],
                'ended_at' => $data['ended_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'],
                'comment' => $data['comment'] ?? null,
            ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
        });
    }

    public function createProductionRecord(int $organizationId, int $userId, array $data): MachineryProductionRecord
    {
        return DB::transaction(function () use ($organizationId, $userId, $data): MachineryProductionRecord {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $projectId = (int) $data['project_id'];
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
            $this->assertAssetProjectConsistency($asset, $projectId);
            $shift = $this->findOptionalShift($data['shift_report_id'] ?? null, $organizationId);

            if ($shift !== null) {
                $this->assertShiftLinkConsistency($shift, (int) $asset->id, $projectId);
            }

            return MachineryProductionRecord::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'shift_report_id' => $data['shift_report_id'] ?? null,
                'recorded_by_user_id' => $userId,
                'recorded_at' => $data['recorded_at'],
                'quantity' => $data['quantity'],
                'unit' => $data['unit'],
                'comment' => $data['comment'] ?? null,
            ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
        });
    }

    public function createFuelIssue(int $organizationId, int $userId, array $data): MachineryFuelIssue
    {
        return DB::transaction(function () use ($organizationId, $userId, $data): MachineryFuelIssue {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $projectId = (int) $data['project_id'];
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
            $this->assertAssetProjectConsistency($asset, $projectId);

            return MachineryFuelIssue::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'issued_by_user_id' => $userId,
                'issued_at' => $data['issued_at'],
                'fuel_type' => $data['fuel_type'],
                'quantity' => $data['quantity'],
                'unit' => $data['unit'],
                'cost' => $data['cost'] ?? 0,
                'comment' => $data['comment'] ?? null,
            ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
        });
    }

    public function createMaintenanceOrder(int $organizationId, int $userId, array $data): MachineryMaintenanceOrder
    {
        $asset = $this->requireAsset((int) $data['asset_id'], $organizationId);
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data, $asset): MachineryMaintenanceOrder {
            $order = MachineryMaintenanceOrder::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
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
            $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Maintenance);

            return $order->fresh(['asset:id,name,asset_code,status', 'project:id,name']);
        });
    }

    public function completeMaintenanceOrder(MachineryMaintenanceOrder $order, int $userId, ?string $comment): MachineryMaintenanceOrder
    {
        if (! in_array($order->status, ['open', 'in_progress'], true)) {
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
            $asset = $order->asset()->first();
            if ($asset !== null) {
                $this->completeCanonicalMaintenance($asset, $order, $userId, $comment);
            }

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

    private function assertAssetProjectConsistency(
        MachineryAsset $asset,
        int $projectId,
        bool $requireActiveAssignment = true,
    ): void {
        if ((int) $asset->current_project_id !== $projectId) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_project_mismatch'));
        }

        if (
            $requireActiveAssignment
            && ! MachineryAssignment::forOrganization((int) $asset->organization_id)
                ->where('asset_id', $asset->id)
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->exists()
        ) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_active_assignment_missing'));
        }
    }

    private function assertShiftLinkConsistency(
        MachineryShiftReport $shift,
        int $assetId,
        int $projectId,
    ): void {
        if ((int) $shift->asset_id !== $assetId || (int) $shift->project_id !== $projectId) {
            throw new DomainException(trans_message('machinery_operations.errors.shift_link_mismatch'));
        }
    }

    private function assertAssignmentLinkConsistency(
        mixed $assignmentId,
        int $organizationId,
        int $assetId,
        int $projectId,
    ): void {
        if ($assignmentId === null) {
            return;
        }

        $matches = MachineryAssignment::forOrganization($organizationId)
            ->whereKey((int) $assignmentId)
            ->where('asset_id', $assetId)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->exists();

        if (! $matches) {
            throw new DomainException(trans_message('machinery_operations.errors.assignment_link_mismatch'));
        }
    }

    private function assertNoOverlappingActiveAssignment(
        MachineryAsset $asset,
        mixed $plannedStartAt,
        mixed $plannedEndAt,
    ): void {
        $overlap = MachineryAssignment::forOrganization((int) $asset->organization_id)
            ->where('asset_id', $asset->id)
            ->where('status', 'active')
            ->when(
                $plannedEndAt !== null,
                static fn ($query) => $query->where('planned_start_at', '<', $plannedEndAt),
            )
            ->where(static function ($query) use ($plannedStartAt): void {
                $query->whereNull('planned_end_at')
                    ->orWhere('planned_end_at', '>', $plannedStartAt);
            })
            ->exists();

        if ($overlap) {
            throw new DomainException(trans_message('machinery_operations.errors.assignment_period_overlap'));
        }
    }

    private function requireLockedAsset(int $id, int $organizationId): MachineryAsset
    {
        $asset = MachineryAsset::forOrganization($organizationId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if ($asset === null) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_not_found'));
        }

        return $asset;
    }

    private function findOptionalShift(mixed $shiftId, int $organizationId): ?MachineryShiftReport
    {
        if ($shiftId === null) {
            return null;
        }

        $shift = MachineryShiftReport::forOrganization($organizationId)
            ->whereKey((int) $shiftId)
            ->lockForUpdate()
            ->first();

        if ($shift === null) {
            throw new DomainException(trans_message('machinery_operations.errors.shift_not_found'));
        }

        return $shift;
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
        if (! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->exists()) {
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

        if (! $exists) {
            throw new DomainException(trans_message('machinery_operations.errors.machinery_not_found'));
        }
    }

    private function assertOptionalScheduleTaskBelongsToOrganization(mixed $scheduleTaskId, int $organizationId): void
    {
        if ($scheduleTaskId === null) {
            return;
        }

        if (! ScheduleTask::query()->where('id', (int) $scheduleTaskId)->where('organization_id', $organizationId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.schedule_task_not_found'));
        }
    }

    private function nextNumber(string $prefix, int $organizationId): string
    {
        return sprintf('%s-%d-%s', $prefix, $organizationId, now()->format('YmdHisv'));
    }

    private function moveCanonicalToProject(MachineryAsset $asset, int $projectId, int $actorId, string $operationStatus): void
    {
        $canonical = $this->canonicalAsset($asset);
        if ($canonical === null) {
            return;
        }

        $this->organizationAssets->move(
            $canonical,
            new AssetPlacementData(projectId: $projectId),
            $actorId,
            'machinery_assigned',
        );
        $this->updateCanonicalState($asset, operationStatus: $operationStatus);
    }

    private function updateCanonicalState(
        MachineryAsset $asset,
        ?string $operationStatus = null,
        ?AssetTechnicalStatus $technicalStatus = null,
        ?AssetLifecycleStatus $lifecycleStatus = null,
        bool $clearPlacement = false,
    ): void {
        $canonical = $this->canonicalAsset($asset, true);
        if ($canonical === null) {
            return;
        }

        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        if ($operationStatus !== null) {
            $metadata['machinery_operation_status'] = $operationStatus;
        }

        $attributes = ['metadata' => $metadata];
        if ($technicalStatus !== null) {
            $attributes['technical_status'] = $technicalStatus;
        }
        if ($lifecycleStatus !== null) {
            $attributes['lifecycle_status'] = $lifecycleStatus;
        }
        if ($clearPlacement) {
            $attributes['current_project_id'] = null;
            $attributes['current_warehouse_id'] = null;
            $attributes['responsible_user_id'] = null;
        }

        $canonical->update($attributes);
    }

    private function completeCanonicalMaintenance(
        MachineryAsset $asset,
        MachineryMaintenanceOrder $order,
        int $userId,
        ?string $comment,
    ): void {
        $canonical = $this->canonicalAsset($asset, true);
        if ($canonical === null) {
            return;
        }

        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        $metadata['machinery_operation_status'] = $canonical->current_project_id === null ? 'available' : 'assigned';
        $metadata['last_control_inspection'] = [
            'result' => 'serviceable',
            'maintenance_order_id' => (int) $order->id,
            'inspected_by_user_id' => $userId,
            'comment' => $comment,
            'occurred_at' => now()->toIso8601String(),
        ];
        $canonical->update([
            'technical_status' => AssetTechnicalStatus::Serviceable,
            'metadata' => $metadata,
        ]);
    }

    private function canonicalAsset(MachineryAsset $asset, bool $lock = false): ?OrganizationAsset
    {
        if ($asset->organization_asset_id === null) {
            return null;
        }

        return OrganizationAsset::forOrganization((int) $asset->organization_id)
            ->whereKey((int) $asset->organization_asset_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
