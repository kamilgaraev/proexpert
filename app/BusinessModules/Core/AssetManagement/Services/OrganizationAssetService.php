<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Services;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\AssetCustodyEvent;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class OrganizationAssetService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function create(int $organizationId, CreateOrganizationAssetData $data): OrganizationAsset
    {
        return DB::transaction(function () use ($organizationId, $data): OrganizationAsset {
            $this->assertOrganizationReference('materials', $data->materialId, $organizationId);
            $this->assertOrganizationReference('machinery', $data->machineryId, $organizationId);

            if ($data->actorId !== null) {
                $this->assertOrganizationUser($data->actorId, $organizationId);
            }

            if ($data->placement !== null) {
                $this->assertPlacement($data->placement, $organizationId);
            }

            $placement = $data->placement ?? new AssetPlacementData;
            $asset = OrganizationAsset::query()->create([
                'organization_id' => $organizationId,
                'material_id' => $data->materialId,
                'machinery_id' => $data->machineryId,
                'name' => $data->name,
                'inventory_number' => $data->inventoryNumber,
                'serial_number' => $data->serialNumber,
                'qr_code' => $data->qrCode,
                'accounting_mode' => $data->accountingMode,
                'ownership_type' => $data->ownershipType,
                'lifecycle_status' => AssetLifecycleStatus::Active,
                'technical_status' => AssetTechnicalStatus::Serviceable,
                ...$this->placementAttributes($placement),
                'metadata' => $data->metadata,
            ]);

            $asset->operationProfile()->create([
                'operational_mode' => $data->operationalMode,
                'tracks_meter' => $data->tracksMeter,
                'tracks_fuel' => $data->tracksFuel,
                'tracks_production' => $data->tracksProduction,
                'maintenance_enabled' => $data->maintenanceEnabled,
                'meter_unit' => $data->meterUnit,
                'operating_cost_per_hour' => $data->operatingCostPerHour,
                'fuel_type' => $data->fuelType,
                'fuel_consumption_rate' => $data->fuelConsumptionRate,
                'meter_value' => $data->meterValue,
            ]);

            if ($data->placement !== null) {
                $this->recordCustodyEvent($asset, $placement, $data->actorId, 'created');
            }

            return $asset->load('operationProfile');
        });
    }

    /** @param array<string, mixed>|null $metadata */
    public function move(
        OrganizationAsset $asset,
        AssetPlacementData $placement,
        int $actorId,
        string $eventType = 'moved',
        ?array $metadata = null,
    ): OrganizationAsset {
        return DB::transaction(function () use ($asset, $placement, $actorId, $eventType, $metadata): OrganizationAsset {
            $lockedAsset = OrganizationAsset::query()->lockForUpdate()->find($asset->getKey());

            if ($lockedAsset === null) {
                throw new DomainException(trans_message('asset_management.errors.asset_not_found'));
            }

            if ($lockedAsset->lifecycle_status !== AssetLifecycleStatus::Active) {
                throw new DomainException(trans_message('asset_management.errors.asset_not_active'));
            }

            $organizationId = (int) $lockedAsset->organization_id;
            $this->assertOrganizationUser($actorId, $organizationId);
            $this->assertPlacement($placement, $organizationId);

            $from = [
                'from_warehouse_id' => $lockedAsset->current_warehouse_id,
                'from_project_id' => $lockedAsset->current_project_id,
                'from_user_id' => $lockedAsset->responsible_user_id,
            ];

            $lockedAsset->update($this->placementAttributes($placement));
            $this->recordCustodyEvent($lockedAsset, $placement, $actorId, $eventType, $from, $metadata);

            return $lockedAsset->refresh()->load('operationProfile');
        });
    }

    public function retire(
        OrganizationAsset $asset,
        int $actorId,
        AssetLifecycleStatus $outcome,
        string $reason,
    ): OrganizationAsset {
        if (! in_array($outcome, [AssetLifecycleStatus::Retired, AssetLifecycleStatus::Lost], true)) {
            throw new DomainException(trans_message('asset_management.errors.invalid_lifecycle_status'));
        }

        return DB::transaction(function () use ($asset, $actorId, $outcome, $reason): OrganizationAsset {
            $lockedAsset = OrganizationAsset::query()->lockForUpdate()->find($asset->getKey());

            if ($lockedAsset === null) {
                throw new DomainException(trans_message('asset_management.errors.asset_not_found'));
            }

            if ($lockedAsset->lifecycle_status !== AssetLifecycleStatus::Active) {
                throw new DomainException(trans_message('basic_warehouse.serialized.already_inactive'));
            }

            $organizationId = (int) $lockedAsset->organization_id;
            $this->assertOrganizationUser($actorId, $organizationId);
            $from = [
                'from_warehouse_id' => $lockedAsset->current_warehouse_id,
                'from_project_id' => $lockedAsset->current_project_id,
                'from_user_id' => $lockedAsset->responsible_user_id,
            ];

            $lockedAsset->update([
                'lifecycle_status' => $outcome,
                'current_warehouse_id' => null,
                'current_project_id' => null,
                'responsible_user_id' => null,
            ]);
            $this->recordCustodyEvent(
                $lockedAsset,
                new AssetPlacementData,
                $actorId,
                $outcome->value,
                $from,
                ['reason' => trim($reason)],
            );

            return $lockedAsset->refresh()->load([
                'operationProfile',
                'currentWarehouse',
                'currentProject',
                'responsibleUser',
            ]);
        });
    }

    /**
     * @return list<string>
     */
    public function availableActions(OrganizationAsset $asset, User $actor): array
    {
        $organizationId = (int) $asset->organization_id;

        if (
            $asset->lifecycle_status !== AssetLifecycleStatus::Active
            || ! $this->userBelongsToOrganization((int) $actor->id, $organizationId)
        ) {
            return [];
        }

        $context = ['organization_id' => $organizationId];
        $actions = [];

        if ($this->canAny($actor, $context, ['warehouse.transfers', 'machinery-operations.edit'])) {
            $actions[] = 'move';
        }

        $profile = $asset->relationLoaded('operationProfile')
            ? $asset->operationProfile
            : $asset->operationProfile()->first();

        if (
            $profile?->operational_mode === AssetOperationalMode::ShiftOperation
            && $asset->technical_status === AssetTechnicalStatus::Serviceable
            && $this->authorization->can($actor, 'machinery-operations.shifts.create', $context)
        ) {
            $actions[] = 'start_shift';
        }

        if (
            $profile?->maintenance_enabled === true
            && $asset->technical_status !== AssetTechnicalStatus::Maintenance
            && $this->authorization->can($actor, 'machinery-operations.downtime.manage', $context)
        ) {
            $actions[] = 'send_to_maintenance';
        }

        if ($this->canAny($actor, $context, ['warehouse.write_offs', 'machinery-operations.delete'])) {
            $actions[] = 'retire';
        }

        return $actions;
    }

    /**
     * @param  array{organization_id: int}  $context
     * @param  list<string>  $permissions
     */
    private function canAny(User $actor, array $context, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->authorization->can($actor, $permission, $context)) {
                return true;
            }
        }

        return false;
    }

    private function assertPlacement(AssetPlacementData $placement, int $organizationId): void
    {
        if ($placement->destinationCount() !== 1) {
            throw new DomainException(trans_message('asset_management.errors.single_destination_required'));
        }

        $this->assertOrganizationReference('organization_warehouses', $placement->warehouseId, $organizationId);
        $this->assertProjectReference($placement->projectId, $organizationId);

        if ($placement->userId !== null) {
            $this->assertOrganizationUser($placement->userId, $organizationId);
        }
    }

    private function assertOrganizationReference(string $table, ?int $id, int $organizationId): void
    {
        if ($id === null) {
            return;
        }

        $exists = DB::table($table)
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('asset_management.errors.foreign_reference'));
        }
    }

    private function assertProjectReference(?int $projectId, int $organizationId): void
    {
        if ($projectId !== null && ! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('asset_management.errors.foreign_reference'));
        }
    }

    private function assertOrganizationUser(int $userId, int $organizationId): void
    {
        if (! $this->userBelongsToOrganization($userId, $organizationId)) {
            throw new DomainException(trans_message('asset_management.errors.foreign_user'));
        }
    }

    private function userBelongsToOrganization(int $userId, int $organizationId): bool
    {
        return DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array{current_warehouse_id: int|null, current_project_id: int|null, responsible_user_id: int|null}
     */
    private function placementAttributes(AssetPlacementData $placement): array
    {
        return [
            'current_warehouse_id' => $placement->warehouseId,
            'current_project_id' => $placement->projectId,
            'responsible_user_id' => $placement->userId,
        ];
    }

    /**
     * @param  array{from_warehouse_id?: int|null, from_project_id?: int|null, from_user_id?: int|null}  $from
     */
    private function recordCustodyEvent(
        OrganizationAsset $asset,
        AssetPlacementData $placement,
        ?int $actorId,
        string $eventType,
        array $from = [],
        ?array $metadata = null,
    ): AssetCustodyEvent {
        return $asset->custodyEvents()->create([
            'organization_id' => $asset->organization_id,
            'actor_user_id' => $actorId,
            'event_type' => $eventType,
            'from_warehouse_id' => $from['from_warehouse_id'] ?? null,
            'from_project_id' => $from['from_project_id'] ?? null,
            'from_user_id' => $from['from_user_id'] ?? null,
            'to_warehouse_id' => $placement->warehouseId,
            'to_project_id' => $placement->projectId,
            'to_user_id' => $placement->userId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
