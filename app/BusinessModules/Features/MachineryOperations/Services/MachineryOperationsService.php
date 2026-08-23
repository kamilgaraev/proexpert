<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\MachineryOperations\DTO\CreateMachineryAssetData;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDefect;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftInspection;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Models\MaintenanceInspection;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\ScheduleTask;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MachineryOperationsService
{
    private const ASSET_RELATIONS = ['machinery:id,name,code,category', 'currentProject:id,name', 'currentScheduleTask:id,name'];

    private const SHIFT_RELATIONS = [
        'asset:id,name,asset_code,status,operating_cost_per_hour,ownership_type,metadata',
        'project:id,name',
        'assignment:id,status',
        'scheduleTask:id,name',
        'constructionJournalEntry:id,journal_id,schedule_task_id,entry_date,status',
        'inspections:id,shift_report_id,inspection_type,result,notes,evidence,defects,inspected_by_user_id,inspected_at',
    ];

    public function __construct(
        private readonly MachineryAssetReadRepository $assets,
        private readonly OrganizationAssetService $organizationAssets,
        private readonly MachineryAssetRegistryProjector $assetProjector,
        private readonly MachineryWorkflowPolicy $workflow,
        private readonly MachineryCostService $costs,
        private readonly SiteRequestAssetProjectionService $siteRequestProjection,
        private readonly WarehouseService $warehouses,
    ) {}

    public function paginateAssets(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return $this->assets->paginate($organizationId, $perPage, $filters);
    }

    public function createAsset(int $organizationId, int $actorId, CreateMachineryAssetData $data): MachineryAsset
    {
        return DB::transaction(function () use ($organizationId, $actorId, $data): MachineryAsset {
            $canonical = $this->organizationAssets->create(
                $organizationId,
                new CreateOrganizationAssetData(
                    name: trim($data->name),
                    inventoryNumber: trim($data->inventoryNumber),
                    serialNumber: $data->serialNumber !== null ? trim($data->serialNumber) : null,
                    accountingMode: AssetAccountingMode::Serialized,
                    ownershipType: $data->ownershipType,
                    actorId: $actorId,
                    metadata: [
                        'asset_type' => $data->assetType->value,
                        'canonical_source' => 'machinery_operations',
                    ],
                    operationalMode: AssetOperationalMode::ShiftOperation,
                    tracksMeter: $data->tracksMeter,
                    tracksFuel: $data->tracksFuel,
                    tracksProduction: $data->tracksProduction,
                    maintenanceEnabled: $data->maintenanceEnabled,
                    meterUnit: $data->meterUnit,
                    operatingCostPerHour: $data->operatingCostPerHour,
                    fuelType: $data->fuelType,
                    fuelConsumptionRate: $data->fuelConsumptionRate,
                    meterValue: $data->meterValue,
                ),
            );

            return $this->assetProjector->project($canonical)->fresh([
                'organizationAsset.operationProfile',
                'organizationAsset.currentProject:id,name',
            ]);
        });
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
            ->with(['asset:id,name,asset_code,status', 'project:id,name', 'inspection'])
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(! empty($filters['asset_id']), fn ($query) => $query->where('asset_id', (int) $filters['asset_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
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
            'assignments' => MachineryAssignment::forOrganization($organizationId)->where('asset_id', $id)->with(['project:id,name', 'assetRequest'])->latest()->limit(50)->get(),
            'shifts' => MachineryShiftReport::forOrganization($organizationId)->where('asset_id', $id)->with('project:id,name')->latest('report_date')->limit(50)->get(),
            'fuel_issues' => MachineryFuelIssue::forOrganization($organizationId)->where('asset_id', $id)->whereNull('cancelled_at')->latest('issued_at')->limit(50)->get(),
            'maintenance_orders' => MachineryMaintenanceOrder::forOrganization($organizationId)->where('asset_id', $id)->latest()->limit(50)->get(),
            'custody_history' => $asset->organizationAsset?->custodyEvents()->latest('occurred_at')->limit(100)->get() ?? [],
            'documents' => [],
            'costs' => [
                'fuel' => (float) MachineryFuelIssue::forOrganization($organizationId)->where('asset_id', $id)->whereNull('cancelled_at')->sum('cost'),
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
            $this->enforceTransitionInvariant('reassign', $lockedAsset);
            $this->assertNoOverlappingActiveAssignment(
                $lockedAsset,
                $data['planned_start_at'],
                $data['planned_end_at'] ?? null,
            );

            $assignment = MachineryAssignment::query()->create([
                'organization_id' => $lockedAsset->organization_id,
                'asset_request_id' => $data['asset_request_id'] ?? null,
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
        return DB::transaction(function () use ($asset): MachineryAsset {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);
            if ($this->workflow->status($lockedAsset) !== 'assigned') {
                throw new DomainException(trans_message('machinery_operations.errors.asset_start_invalid_status'));
            }

            $lockedAsset->update(['status' => 'in_operation']);
            $this->updateCanonicalState($lockedAsset, operationStatus: 'in_operation');

            return $lockedAsset->fresh(self::ASSET_RELATIONS);
        });
    }

    public function setMaintenance(MachineryAsset $asset): MachineryAsset
    {
        return DB::transaction(function () use ($asset): MachineryAsset {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);
            if (! in_array($this->workflow->status($lockedAsset), ['available', 'assigned', 'in_operation', 'unavailable'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_maintenance_invalid_status'));
            }
            $this->enforceTransitionInvariant('maintenance', $lockedAsset);

            $lockedAsset->update(['status' => 'maintenance']);
            $this->updateCanonicalState($lockedAsset, technicalStatus: AssetTechnicalStatus::Maintenance);

            return $lockedAsset->fresh(self::ASSET_RELATIONS);
        });
    }

    public function setUnavailable(MachineryAsset $asset): MachineryAsset
    {
        return DB::transaction(function () use ($asset): MachineryAsset {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);
            if ($this->workflow->status($lockedAsset) === 'archived') {
                throw new DomainException(trans_message('machinery_operations.errors.asset_unavailable_invalid_status'));
            }
            $this->enforceTransitionInvariant('unavailable', $lockedAsset);

            $lockedAsset->update(['status' => 'unavailable']);
            $this->updateCanonicalState($lockedAsset, technicalStatus: AssetTechnicalStatus::Unavailable);

            return $lockedAsset->fresh(self::ASSET_RELATIONS);
        });
    }

    public function returnAvailable(MachineryAsset $asset, int $actorId): MachineryAsset
    {
        return DB::transaction(function () use ($asset, $actorId): MachineryAsset {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);
            if (! in_array($this->workflow->status($lockedAsset), ['assigned', 'in_operation', 'maintenance', 'unavailable'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_available_invalid_status'));
            }
            $this->enforceTransitionInvariant('return_available', $lockedAsset);
            $lockedAsset->update([
                'status' => 'available',
                'current_project_id' => null,
                'current_schedule_task_id' => null,
            ]);
            $this->updateCanonicalState(
                $lockedAsset,
                operationStatus: 'available',
                technicalStatus: AssetTechnicalStatus::Serviceable,
                clearPlacement: true,
            );

            $assignments = MachineryAssignment::forOrganization((int) $lockedAsset->organization_id)
                ->where('asset_id', $lockedAsset->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            foreach ($assignments as $assignment) {
                $assignment->update(['status' => 'completed', 'actual_end_at' => now()]);
                $this->siteRequestProjection->completeFromAssignment($assignment, $actorId);
            }

            return $lockedAsset->fresh(self::ASSET_RELATIONS);
        });
    }

    public function archiveAsset(MachineryAsset $asset): MachineryAsset
    {
        return DB::transaction(function () use ($asset): MachineryAsset {
            $lockedAsset = $this->requireLockedAsset((int) $asset->id, (int) $asset->organization_id);
            if (in_array($this->workflow->status($lockedAsset), ['assigned', 'in_operation'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_archive_invalid_status'));
            }
            $this->enforceTransitionInvariant('archive', $lockedAsset);

            $lockedAsset->update([
                'status' => 'archived',
                'archived_at' => now(),
            ]);
            $this->updateCanonicalState($lockedAsset, lifecycleStatus: AssetLifecycleStatus::Retired);

            return $lockedAsset->fresh(self::ASSET_RELATIONS);
        });
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

            $currentMeter = $this->currentMeter($asset);
            $meterStart = isset($data['meter_start']) ? (float) $data['meter_start'] : $currentMeter;
            $hasOpenShift = MachineryShiftReport::forOrganization($organizationId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', MachineryShiftInvariant::OPEN_STATUSES)
                ->exists();
            $this->enforceShiftInvariant(static fn () => MachineryShiftInvariant::assertStartAllowed(
                (string) $asset->status,
                $hasOpenShift,
                $meterStart,
                $currentMeter,
            ));
            $inspectionData = $data['pre_shift_inspection'];
            $inspectionDefects = is_array($inspectionData['defects'] ?? null) ? $inspectionData['defects'] : [];
            $blocksOperation = $this->inspectionBlocksOperation(
                (string) $inspectionData['result'],
                $inspectionDefects,
            );
            $scheduleTaskId = $this->shiftScheduleTaskId($asset, $data['assignment_id'] ?? null);
            $journalEntryId = $this->resolveJournalEntryId(
                $projectId,
                $scheduleTaskId,
                (string) $data['report_date'],
            );

            $shift = MachineryShiftReport::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'assignment_id' => $data['assignment_id'] ?? null,
                'schedule_task_id' => $scheduleTaskId,
                'construction_journal_entry_id' => $journalEntryId,
                'reported_by_user_id' => $userId,
                'report_date' => $data['report_date'],
                'status' => $blocksOperation ? 'blocked' : 'draft',
                'planned_hours' => $data['planned_hours'] ?? 0,
                'actual_hours' => 0,
                'fuel_consumed' => 0,
                'meter_start' => $meterStart,
                'meter_end' => null,
                'work_description' => null,
                'started_at' => $blocksOperation ? null : now(),
            ]);
            $this->recordShiftInspection($shift, $userId, 'pre_shift', $inspectionData);
            if ($blocksOperation) {
                $asset->update(['status' => 'unavailable']);
                $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Unavailable);
            }

            return $shift->fresh(self::SHIFT_RELATIONS);
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
        return DB::transaction(function () use ($shift): MachineryShiftReport {
            $lockedShift = $this->requireLockedShift((int) $shift->id, (int) $shift->organization_id);
            $this->enforceShiftInvariant(
                static fn () => MachineryShiftInvariant::assertSubmitAllowed((string) $lockedShift->status),
            );
            if (! $lockedShift->inspections()->where('inspection_type', 'pre_shift')->exists()
                || ! $lockedShift->inspections()->where('inspection_type', 'post_shift')->exists()
            ) {
                throw new DomainException(trans_message('machinery_operations.errors.shift_inspections_required'));
            }
            $lockedShift->update(['status' => 'submitted', 'submitted_at' => now()]);

            return $lockedShift->fresh(self::SHIFT_RELATIONS);
        });
    }

    public function finishShift(MachineryShiftReport $shift, int $userId, array $data): MachineryShiftReport
    {
        return DB::transaction(function () use ($shift, $userId, $data): MachineryShiftReport {
            $lockedShift = $this->requireLockedShift((int) $shift->id, (int) $shift->organization_id);
            $this->enforceShiftInvariant(
                static fn () => MachineryShiftInvariant::assertFinishAllowed((string) $lockedShift->status),
            );
            $inspectionData = $data['post_shift_inspection'];
            $inspectionDefects = is_array($inspectionData['defects'] ?? null) ? $inspectionData['defects'] : [];
            $blocksOperation = $this->inspectionBlocksOperation(
                (string) $inspectionData['result'],
                $inspectionDefects,
            );

            $meterEnd = isset($data['meter_end']) ? (float) $data['meter_end'] : null;
            $actualHours = (float) $data['actual_hours'];
            if ($lockedShift->meter_start !== null && $meterEnd !== null) {
                $actualHours = $this->translatedMeterDelta((float) $lockedShift->meter_start, $meterEnd);
            }

            $inspection = $this->recordShiftInspection($lockedShift, $userId, 'post_shift', $inspectionData);
            $lockedShift->update([
                'status' => 'completed',
                'actual_hours' => $actualHours,
                'fuel_consumed' => $data['fuel_consumed'],
                'meter_end' => $meterEnd ?? $lockedShift->meter_end,
                'work_description' => $data['work_description'] ?? $lockedShift->work_description,
                'finished_by_user_id' => $userId,
                'finished_at' => now(),
                'finish_evidence' => [
                    'version' => 1,
                    'state_before' => 'draft',
                    'actor_user_id' => $userId,
                    'meter_start' => $lockedShift->meter_start,
                    'meter_end' => $meterEnd,
                    'actual_hours' => $actualHours,
                    'post_shift_inspection_id' => (int) $inspection->id,
                    'post_shift_result' => (string) $inspection->result,
                    'captured_at' => now()->toIso8601String(),
                ],
            ]);
            if ($blocksOperation) {
                $asset = $this->requireLockedAsset((int) $lockedShift->asset_id, (int) $lockedShift->organization_id);
                $asset->update(['status' => 'unavailable']);
                $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Unavailable);
            }

            return $lockedShift->fresh(self::SHIFT_RELATIONS);
        });
    }

    public function approveShift(MachineryShiftReport $shift, int $userId): MachineryShiftReport
    {
        return DB::transaction(function () use ($shift, $userId): MachineryShiftReport {
            $lockedShift = $this->requireLockedShift((int) $shift->id, (int) $shift->organization_id);
            if ($lockedShift->status !== 'submitted') {
                throw new DomainException(trans_message('machinery_operations.errors.shift_approve_invalid_status'));
            }
            $legacy = MachineryAsset::query()->whereKey($lockedShift->asset_id)->lockForUpdate()->first();
            $canonical = $legacy !== null ? $this->canonicalAsset($legacy, true) : null;
            $profile = $canonical?->operationProfile()->lockForUpdate()->first();
            $hourlyRate = (float) ($profile?->operating_cost_per_hour ?? $legacy?->operating_cost_per_hour ?? 0);
            if ($lockedShift->meter_end !== null && $profile?->meter_value !== null && (float) $lockedShift->meter_end < (float) $profile->meter_value) {
                throw new DomainException(trans_message('machinery_operations.errors.meter_end_before_current'));
            }
            $lockedShift->update([
                'status' => 'approved',
                'construction_journal_entry_id' => $lockedShift->construction_journal_entry_id
                    ?? $this->resolveJournalEntryId(
                        (int) $lockedShift->project_id,
                        $lockedShift->schedule_task_id !== null ? (int) $lockedShift->schedule_task_id : null,
                        $lockedShift->report_date->toDateString(),
                    ),
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'hourly_rate_snapshot' => $hourlyRate,
                'cost_evidence' => [
                    'version' => 1,
                    'source' => $profile !== null ? 'canonical_operation_profile' : 'legacy_asset_fallback',
                    'asset_id' => (int) $lockedShift->asset_id,
                    'organization_asset_id' => $canonical?->id,
                    'hourly_rate' => $hourlyRate,
                    'captured_at' => now()->toIso8601String(),
                ],
            ]);

            if ($lockedShift->meter_end !== null) {
                $profile?->update(['meter_value' => $lockedShift->meter_end]);
                $legacy?->update(['meter_hours' => $lockedShift->meter_end]);
            }

            return $lockedShift->fresh(self::SHIFT_RELATIONS);
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

    public function cancelShift(MachineryShiftReport $shift, int $userId, string $reason): MachineryShiftReport
    {
        return DB::transaction(function () use ($shift, $userId, $reason): MachineryShiftReport {
            $lockedShift = $this->requireLockedShift((int) $shift->id, (int) $shift->organization_id);
            $normalizedReason = trim($reason);
            if ($normalizedReason === '') {
                throw new DomainException(trans_message('machinery_operations.errors.cancellation_reason_required'));
            }
            if ($lockedShift->status === 'cancelled') {
                if ($lockedShift->cancellation_reason !== $normalizedReason) {
                    throw new DomainException(trans_message('machinery_operations.errors.shift_cancel_conflict'));
                }

                return $lockedShift->fresh(self::SHIFT_RELATIONS);
            }

            $fuelIssues = MachineryFuelIssue::forOrganization((int) $lockedShift->organization_id)
                ->where('shift_report_id', $lockedShift->id)
                ->whereNull('cancelled_at')
                ->with('warehouseMovement')
                ->lockForUpdate()
                ->get();
            foreach ($fuelIssues as $fuelIssue) {
                $movement = $fuelIssue->warehouseMovement;
                if ($movement === null) {
                    throw new DomainException(trans_message('machinery_operations.errors.fuel_reversal_source_missing'));
                }
                $reversal = $this->warehouses->receiveAsset(
                    (int) $fuelIssue->organization_id,
                    (int) $fuelIssue->warehouse_id,
                    (int) $fuelIssue->material_id,
                    (float) $fuelIssue->quantity,
                    (float) $movement->price,
                    [
                        'project_id' => (int) $fuelIssue->project_id,
                        'user_id' => $userId,
                        'reason' => 'machinery_fuel_reversal',
                        'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
                        'idempotency_key' => 'machinery-fuel-reversal-'.$fuelIssue->id,
                        'source_type' => 'machinery_fuel_issue',
                        'source_id' => (int) $fuelIssue->id,
                        'reversal_of_movement_id' => (int) $movement->id,
                    ],
                );
                /** @var WarehouseMovement $reversalMovement */
                $reversalMovement = $reversal['movement'];
                $fuelIssue->update([
                    'reversal_movement_id' => $reversalMovement->id,
                    'cancelled_by_user_id' => $userId,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $normalizedReason,
                ]);
            }

            $lockedShift->update([
                'status' => 'cancelled',
                'cancelled_by_user_id' => $userId,
                'cancelled_at' => now(),
                'cancellation_reason' => $normalizedReason,
            ]);
            $asset = $this->requireLockedAsset((int) $lockedShift->asset_id, (int) $lockedShift->organization_id);
            if ($this->workflow->status($asset) === 'in_operation') {
                $asset->update(['status' => 'assigned']);
                $this->updateCanonicalState($asset, operationStatus: 'assigned');
            }

            return $lockedShift->fresh(self::SHIFT_RELATIONS);
        });
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
                'reason_code' => $this->dictionaryCode((string) $data['reason'], self::DOWNTIME_CODES),
                'reason_original' => $this->dictionaryOriginal((string) $data['reason'], self::DOWNTIME_CODES),
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

    public function createFuelIssue(
        int $organizationId,
        int $userId,
        string $idempotencyKey,
        array $data,
    ): MachineryFuelIssue {
        return DB::transaction(function () use ($organizationId, $userId, $idempotencyKey, $data): MachineryFuelIssue {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $projectId = (int) $data['project_id'];
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
            $this->assertAssetProjectConsistency($asset, $projectId);
            $shift = $this->findOptionalShift($data['shift_report_id'], $organizationId);
            if ($shift === null) {
                throw new DomainException(trans_message('machinery_operations.errors.fuel_shift_required'));
            }
            $this->assertShiftLinkConsistency($shift, (int) $asset->id, $projectId);
            $capacity = $this->fuelCapacity($asset);
            $this->enforceOperationInvariant(static fn () => MachineryOperationInvariant::assertFuelAllowed(
                (string) $asset->status,
                (string) $shift->status,
                (float) $data['quantity'],
                $capacity,
            ));

            $warehouseResult = $this->warehouses->writeOffAsset(
                $organizationId,
                (int) $data['warehouse_id'],
                (int) $data['material_id'],
                (float) $data['quantity'],
                [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'related_user_id' => $shift->reported_by_user_id,
                    'reason' => 'machinery_fuel_issue',
                    'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
                    'idempotency_key' => $idempotencyKey,
                    'source_type' => 'machinery_shift',
                    'source_id' => (int) $shift->id,
                ],
            );
            /** @var WarehouseMovement $movement */
            $movement = $warehouseResult['movement'];
            $cost = round((float) $movement->quantity * (float) $movement->price, 2);

            return MachineryFuelIssue::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'shift_report_id' => $shift->id,
                'operator_user_id' => $shift->reported_by_user_id,
                'warehouse_id' => $movement->warehouse_id,
                'material_id' => $movement->material_id,
                'warehouse_movement_id' => $movement->id,
                'issued_by_user_id' => $userId,
                'issued_at' => $data['issued_at'],
                'fuel_type' => $data['fuel_type'],
                'fuel_type_code' => $this->dictionaryCode((string) $data['fuel_type'], self::FUEL_CODES),
                'fuel_type_original' => $this->dictionaryOriginal((string) $data['fuel_type'], self::FUEL_CODES),
                'quantity' => $data['quantity'],
                'unit' => $data['unit'],
                'unit_code' => $this->dictionaryCode((string) $data['unit'], self::FUEL_UNIT_CODES),
                'unit_original' => $this->dictionaryOriginal((string) $data['unit'], self::FUEL_UNIT_CODES),
                'cost' => $cost,
                'comment' => $data['comment'] ?? null,
            ])->fresh(['asset:id,name,asset_code', 'project:id,name']);
        });
    }

    public function createMaintenanceOrder(int $organizationId, int $userId, array $data): MachineryMaintenanceOrder
    {
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data): MachineryMaintenanceOrder {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $hasOpenShift = MachineryShiftReport::forOrganization($organizationId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', MachineryShiftInvariant::OPEN_STATUSES)
                ->exists();
            $hasOpenMaintenance = MachineryMaintenanceOrder::forOrganization($organizationId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->exists();
            $this->enforceOperationInvariant(static fn () => MachineryOperationInvariant::assertMaintenanceAllowed(
                (string) $asset->status,
                $hasOpenShift,
                $hasOpenMaintenance,
            ));
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

            return $order->fresh(['asset:id,name,asset_code,status', 'project:id,name', 'inspection']);
        });
    }

    public function completeMaintenanceOrder(
        MachineryMaintenanceOrder $order,
        int $userId,
        ?string $comment,
        string $inspectionResult = 'serviceable',
        array $inspectionEvidence = [],
    ): MachineryMaintenanceOrder {
        if (! in_array($order->status, ['open', 'in_progress'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.maintenance_complete_invalid_status'));
        }

        if (! in_array($inspectionResult, ['serviceable', 'restricted', 'unavailable'], true)) {
            throw new DomainException(trans_message('machinery_operations.errors.inspection_result_invalid'));
        }

        return DB::transaction(function () use ($order, $userId, $comment, $inspectionResult, $inspectionEvidence): MachineryMaintenanceOrder {
            $order->update([
                'status' => 'completed',
                'completed_by_user_id' => $userId,
                'completed_at' => now(),
                'completion_comment' => $comment,
            ]);

            MaintenanceInspection::query()->create([
                'organization_id' => $order->organization_id,
                'maintenance_order_id' => $order->id,
                'organization_asset_id' => $order->organization_asset_id,
                'asset_id' => $order->asset_id,
                'inspected_by_user_id' => $userId,
                'result' => $inspectionResult,
                'notes' => $comment,
                'evidence' => $inspectionEvidence,
                'inspected_at' => now(),
            ]);

            $legacyStatus = $inspectionResult === 'serviceable' ? 'available' : 'unavailable';
            $order->asset()->update(['status' => $legacyStatus]);
            $asset = $order->asset()->first();
            if ($asset !== null) {
                $this->completeCanonicalMaintenance($asset, $order, $userId, $comment, $inspectionResult);
            }

            return $order->fresh(['asset:id,name,asset_code,status', 'project:id,name', 'inspection']);
        });
    }

    public function reportDefect(int $organizationId, int $userId, array $data): MachineryDefect
    {
        return DB::transaction(function () use ($organizationId, $userId, $data): MachineryDefect {
            $asset = $this->requireLockedAsset((int) $data['asset_id'], $organizationId);
            $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);
            $severity = (string) $data['severity'];
            if (! in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.defect_severity_invalid'));
            }
            $defect = MachineryDefect::query()->create([
                'organization_id' => $organizationId,
                'organization_asset_id' => $asset->organization_asset_id,
                'asset_id' => $asset->id,
                'project_id' => $data['project_id'] ?? $asset->current_project_id,
                'reported_by_user_id' => $userId,
                'defect_code' => (string) $data['defect_code'],
                'severity' => $severity,
                'status' => 'open',
                'description' => (string) $data['description'],
                'reported_at' => $data['reported_at'] ?? now(),
            ]);
            if ($severity === 'critical') {
                $asset->update(['status' => 'unavailable']);
                $this->updateCanonicalState($asset, technicalStatus: AssetTechnicalStatus::Unavailable);
            }

            return $defect->fresh(['asset:id,name,asset_code,status', 'project:id,name']);
        });
    }

    public function costReport(int $organizationId, string $dateFrom, string $dateTo, ?int $projectId = null): array
    {
        if ($projectId !== null) {
            $this->assertProjectBelongsToOrganization($projectId, $organizationId);
        }

        return $this->costs->calculate(
            $organizationId,
            \Carbon\CarbonImmutable::parse($dateFrom),
            \Carbon\CarbonImmutable::parse($dateTo),
            $projectId,
        );
    }

    public function findMaintenanceOrder(int $organizationId, int $id): ?MachineryMaintenanceOrder
    {
        return MachineryMaintenanceOrder::forOrganization($organizationId)
            ->with(['asset:id,name,asset_code,status', 'project:id,name', 'inspection'])
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
            ->whereNull('cancelled_at')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId));

        return [
            'utilization_by_project' => MachineryShiftReport::forOrganization($organizationId)
                ->selectRaw('project_id, sum(actual_hours) as actual_hours, sum(planned_hours) as planned_hours')
                ->where('status', 'approved')
                ->where('report_date', '<=', now()->toDateString())
                ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
                ->groupBy('project_id')
                ->get()
                ->all(),
            'downtime_by_reason' => $downtimes
                ->selectRaw('coalesce(reason_code, reason) as reason, sum(duration_minutes) as duration_minutes, count(*) as count')
                ->groupByRaw('coalesce(reason_code, reason)')
                ->get()
                ->all(),
            'fuel_consumption' => $fuel
                ->selectRaw('coalesce(fuel_type_code, fuel_type) as fuel_type, sum(quantity) as quantity, sum(cost) as cost')
                ->groupByRaw('coalesce(fuel_type_code, fuel_type)')
                ->get()
                ->all(),
            'operating_cost_by_project' => MachineryShiftReport::forOrganization($organizationId)
                ->leftJoin('asset_operation_profiles', 'machinery_shift_reports.organization_asset_id', '=', 'asset_operation_profiles.organization_asset_id')
                ->selectRaw('machinery_shift_reports.project_id, sum(machinery_shift_reports.actual_hours * coalesce(machinery_shift_reports.hourly_rate_snapshot, asset_operation_profiles.operating_cost_per_hour, 0)) as cost')
                ->where('machinery_shift_reports.status', 'approved')
                ->where('machinery_shift_reports.report_date', '<=', now()->toDateString())
                ->when($projectId !== null, fn ($query) => $query->where('machinery_shift_reports.project_id', $projectId))
                ->whereNull('machinery_shift_reports.deleted_at')
                ->groupBy('machinery_shift_reports.project_id')
                ->get()
                ->all(),
            'plan_fact_variance' => MachineryShiftReport::forOrganization($organizationId)
                ->selectRaw('project_id, sum(planned_hours) as planned_hours, sum(actual_hours) as actual_hours, sum(actual_hours - planned_hours) as variance_hours')
                ->where('status', 'approved')
                ->where('report_date', '<=', now()->toDateString())
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

    private function requireLockedShift(int $id, int $organizationId): MachineryShiftReport
    {
        $shift = MachineryShiftReport::forOrganization($organizationId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if ($shift === null) {
            throw new DomainException(trans_message('machinery_operations.errors.shift_not_found'));
        }

        return $shift;
    }

    private function currentMeter(MachineryAsset $asset): ?float
    {
        $canonical = $this->canonicalAsset($asset, true);
        $profile = $canonical?->operationProfile()->lockForUpdate()->first();
        $value = $profile?->meter_value ?? $asset->meter_hours;

        return $value === null ? null : (float) $value;
    }

    private function translatedMeterDelta(float $meterStart, float $meterEnd): float
    {
        try {
            return MachineryShiftInvariant::actualFromMeters($meterStart, $meterEnd);
        } catch (DomainException $exception) {
            throw new DomainException(trans_message('machinery_operations.errors.'.$exception->getMessage()));
        }
    }

    private function enforceShiftInvariant(\Closure $invariant): void
    {
        try {
            $invariant();
        } catch (DomainException $exception) {
            throw new DomainException(trans_message('machinery_operations.errors.'.$exception->getMessage()));
        }
    }

    private function enforceOperationInvariant(\Closure $invariant): void
    {
        try {
            $invariant();
        } catch (DomainException $exception) {
            throw new DomainException(trans_message('machinery_operations.errors.'.$exception->getMessage()));
        }
    }

    private function enforceTransitionInvariant(string $transition, MachineryAsset $asset): void
    {
        $organizationId = (int) $asset->organization_id;
        $assetId = (int) $asset->id;
        $hasOpenShift = MachineryShiftReport::forOrganization($organizationId)
            ->where('asset_id', $assetId)
            ->whereIn('status', MachineryShiftInvariant::OPEN_STATUSES)
            ->exists();
        $hasOpenMaintenance = MachineryMaintenanceOrder::forOrganization($organizationId)
            ->where('asset_id', $assetId)
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
        $hasActiveAssignment = MachineryAssignment::forOrganization($organizationId)
            ->where('asset_id', $assetId)
            ->where('status', 'active')
            ->exists();

        $this->enforceOperationInvariant(static fn () => MachineryOperationInvariant::assertTransitionAllowed(
            $transition,
            $hasOpenShift,
            $hasOpenMaintenance,
            $hasActiveAssignment,
        ));
    }

    private function inspectionBlocksOperation(string $result, array $defects): bool
    {
        try {
            return MachineryShiftInvariant::inspectionBlocksOperation($result, $defects);
        } catch (DomainException $exception) {
            throw new DomainException(trans_message('machinery_operations.errors.'.$exception->getMessage()));
        }
    }

    private function recordShiftInspection(
        MachineryShiftReport $shift,
        int $userId,
        string $inspectionType,
        array $data,
    ): MachineryShiftInspection {
        $defects = is_array($data['defects'] ?? null) ? $data['defects'] : [];
        $inspection = MachineryShiftInspection::query()->create([
            'organization_id' => $shift->organization_id,
            'organization_asset_id' => $shift->organization_asset_id,
            'asset_id' => $shift->asset_id,
            'project_id' => $shift->project_id,
            'shift_report_id' => $shift->id,
            'inspected_by_user_id' => $userId,
            'inspection_type' => $inspectionType,
            'result' => (string) $data['result'],
            'notes' => $data['notes'] ?? null,
            'evidence' => $data['evidence'] ?? [],
            'defects' => $defects,
            'inspected_at' => now(),
        ]);

        foreach ($defects as $defect) {
            $severity = (string) ($defect['severity'] ?? '');
            if (! in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.defect_severity_invalid'));
            }
            MachineryDefect::query()->create([
                'organization_id' => $shift->organization_id,
                'organization_asset_id' => $shift->organization_asset_id,
                'asset_id' => $shift->asset_id,
                'project_id' => $shift->project_id,
                'shift_report_id' => $shift->id,
                'shift_inspection_id' => $inspection->id,
                'reported_by_user_id' => $userId,
                'defect_code' => (string) ($defect['code'] ?? ''),
                'severity' => $severity,
                'status' => 'open',
                'description' => (string) ($defect['description'] ?? ''),
                'reported_at' => now(),
            ]);
        }

        return $inspection;
    }

    private function shiftScheduleTaskId(MachineryAsset $asset, mixed $assignmentId): ?int
    {
        if ($assignmentId !== null) {
            $scheduleTaskId = MachineryAssignment::forOrganization((int) $asset->organization_id)
                ->whereKey((int) $assignmentId)
                ->value('schedule_task_id');
            if ($scheduleTaskId !== null) {
                return (int) $scheduleTaskId;
            }
        }

        return $asset->current_schedule_task_id !== null ? (int) $asset->current_schedule_task_id : null;
    }

    private function resolveJournalEntryId(int $projectId, ?int $scheduleTaskId, string $reportDate): ?int
    {
        if ($scheduleTaskId === null) {
            return null;
        }

        $entries = ConstructionJournalEntry::query()
            ->where('schedule_task_id', $scheduleTaskId)
            ->whereDate('entry_date', $reportDate)
            ->whereIn('status', ['submitted', 'approved'])
            ->whereHas('journal', static fn ($query) => $query->where('project_id', $projectId))
            ->limit(2)
            ->pluck('id');

        return $entries->count() === 1 ? (int) $entries->first() : null;
    }

    private function fuelCapacity(MachineryAsset $asset): ?float
    {
        $canonical = $this->canonicalAsset($asset, true);
        $metadata = is_array($canonical?->metadata) ? $canonical->metadata : $asset->metadata;
        $capacity = is_array($metadata) ? ($metadata['fuel_capacity'] ?? null) : null;

        return is_numeric($capacity) ? (float) $capacity : null;
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
        string $inspectionResult,
    ): void {
        $canonical = $this->canonicalAsset($asset, true);
        if ($canonical === null) {
            return;
        }

        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        $metadata['machinery_operation_status'] = $inspectionResult === 'serviceable'
            ? ($canonical->current_project_id === null ? 'available' : 'assigned')
            : 'unavailable';
        $metadata['last_control_inspection'] = [
            'result' => $inspectionResult,
            'maintenance_order_id' => (int) $order->id,
            'inspected_by_user_id' => $userId,
            'comment' => $comment,
            'occurred_at' => now()->toIso8601String(),
        ];
        $canonical->update([
            'technical_status' => match ($inspectionResult) {
                'serviceable' => AssetTechnicalStatus::Serviceable,
                'restricted' => AssetTechnicalStatus::Restricted,
                default => AssetTechnicalStatus::Unavailable,
            },
            'metadata' => $metadata,
        ]);
    }

    private const DOWNTIME_CODES = ['waiting_material', 'weather', 'breakdown', 'organizational', 'other'];

    private const FUEL_CODES = ['diesel', 'gasoline', 'electricity', 'gas', 'other'];

    private const FUEL_UNIT_CODES = ['l', 'kg', 'kwh', 'm3', 'other'];

    private function dictionaryCode(string $value, array $codes): string
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, $codes, true) ? $normalized : 'other';
    }

    private function dictionaryOriginal(string $value, array $codes): ?string
    {
        return $this->dictionaryCode($value, $codes) === 'other' && mb_strtolower(trim($value)) !== 'other'
            ? trim($value)
            : null;
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
