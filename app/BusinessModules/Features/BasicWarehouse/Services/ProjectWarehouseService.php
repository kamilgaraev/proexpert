<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Models\Project;
use App\Models\User;
use DomainException;

use function trans_message;

final class ProjectWarehouseService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly ProjectAllocationAvailabilityService $allocationAvailability,
    ) {}

    public function getOrCreateProjectWarehouse(int $organizationId, int $projectId, User $actor): OrganizationWarehouse
    {
        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_PROJECT)
            ->where('is_active', true)
            ->first();

        if ($warehouse instanceof OrganizationWarehouse) {
            return $warehouse;
        }

        $project = Project::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($projectId);

        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'name' => trans_message('basic_warehouse.project_material_deliveries.project_warehouse_name', [
                'project' => $project->name,
            ]),
            'code' => 'PRJ-'.$projectId,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false,
            'is_active' => true,
            'settings' => [
                'auto_created' => true,
                'created_by_user_id' => $actor->id,
            ],
        ]);
    }

    public function shipToProject(
        ProjectMaterialDelivery $delivery,
        User $actor,
        float $quantity,
        ?int $responsibleUserId,
        ?string $notes
    ): array {
        if (! $delivery->warehouse_id) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.source_required'));
        }

        $projectWarehouse = $this->getOrCreateProjectWarehouse(
            (int) $delivery->organization_id,
            (int) $delivery->project_id,
            $actor
        );
        $transitWarehouse = $this->getOrCreateTransitWarehouse(
            (int) $delivery->organization_id,
            (int) $delivery->project_id,
            $actor,
        );
        $warehouses = OrganizationWarehouse::query()->where('organization_id', $delivery->organization_id)
            ->whereIn('id', [$delivery->warehouse_id, $transitWarehouse->id])->orderBy('id')->lockForUpdate()->get();
        $sourceWarehouse = $warehouses->firstWhere('id', $delivery->warehouse_id);
        if ($sourceWarehouse === null || ! $sourceWarehouse->is_active
            || (int) $sourceWarehouse->id === (int) $transitWarehouse->id
            || ($sourceWarehouse->settings['purpose'] ?? null) === 'project_delivery_transit'
            || ($sourceWarehouse->project_id !== null && (int) $sourceWarehouse->project_id !== (int) $delivery->project_id)) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.invalid_source_warehouse'));
        }
        $sourceBatches = WarehouseBalance::query()->where('organization_id', $delivery->organization_id)
            ->where('warehouse_id', $delivery->warehouse_id)->where('material_id', $delivery->material_id)
            ->where('available_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()->get();
        $available = 0.0;
        foreach ($sourceBatches as $batch) {
            $available += (float) $batch->available_quantity;
        }
        $allocated = (float) $this->allocationAvailability->outstandingByMaterial(
            (int) $delivery->organization_id, (int) $delivery->warehouse_id, [(int) $delivery->material_id],
        )->get($delivery->material_id, 0);
        $allocation = $delivery->allocation;
        $ownAllocation = $allocation instanceof WarehouseProjectAllocation
            ? $this->allocationAvailability->outstandingForAllocation((int) $delivery->organization_id, $allocation) : 0.0;
        if ($quantity > max(0.0, $available - max(0.0, $allocated - $ownAllocation))
            || ($allocation !== null && $quantity > $ownAllocation)) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.insufficient_unallocated_stock'));
        }
        $result = $this->warehouseService->transferAsset(
            (int) $delivery->organization_id,
            (int) $delivery->warehouse_id,
            (int) $transitWarehouse->id,
            (int) $delivery->material_id,
            $quantity,
            [
                'batch_number' => 'project-delivery:'.$delivery->id,
                'project_id' => (int) $delivery->project_id,
                'user_id' => $actor->id,
                'related_user_id' => $responsibleUserId,
                'project_material_delivery_id' => $delivery->id,
                'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
                'reason' => $notes ?? trans_message('basic_warehouse.project_material_deliveries.shipped'),
            ]
        );

        /** @var WarehouseMovement $movement */
        $movement = $result['movement_out'];

        return [
            'movement' => $movement,
            'project_warehouse' => $projectWarehouse,
        ];
    }

    public function receiveOnProject(
        ProjectMaterialDelivery $delivery,
        User $actor,
        float $quantity,
        ?string $notes,
        string $idempotencyKey,
        string $idempotencyFingerprint,
    ): WarehouseMovement {
        $projectWarehouse = $delivery->project_warehouse_id
            ? OrganizationWarehouse::query()
                ->where('organization_id', (int) $delivery->organization_id)
                ->where('warehouse_type', OrganizationWarehouse::TYPE_PROJECT)
                ->findOrFail((int) $delivery->project_warehouse_id)
            : $this->getOrCreateProjectWarehouse((int) $delivery->organization_id, (int) $delivery->project_id, $actor);

        $transitWarehouse = $this->getOrCreateTransitWarehouse(
            (int) $delivery->organization_id,
            (int) $delivery->project_id,
            $actor,
        );
        $result = $this->warehouseService->transferAsset(
            (int) $delivery->organization_id,
            (int) $transitWarehouse->id,
            (int) $projectWarehouse->id,
            (int) $delivery->material_id,
            $quantity,
            [
                'batch_number' => 'project-delivery:'.$delivery->id,
                'from_batch_number' => 'project-delivery:'.$delivery->id,
                'project_id' => (int) $delivery->project_id,
                'user_id' => $actor->id,
                'project_material_delivery_id' => $delivery->id,
                'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
                'reason' => $notes ?? trans_message('basic_warehouse.project_material_deliveries.received'),
                'idempotency_key' => $idempotencyKey,
                'delivery_idempotency_fingerprint' => $idempotencyFingerprint,
            ],
        );

        return $result['movement_in'];
    }

    public function returnCancelledDelivery(
        ProjectMaterialDelivery $delivery,
        User $actor,
        float $quantity,
        ?string $notes,
    ): WarehouseMovement {
        $transitWarehouse = $this->getOrCreateTransitWarehouse(
            (int) $delivery->organization_id,
            (int) $delivery->project_id,
            $actor,
        );
        $result = $this->warehouseService->transferAsset(
            (int) $delivery->organization_id,
            (int) $transitWarehouse->id,
            (int) $delivery->warehouse_id,
            (int) $delivery->material_id,
            $quantity,
            [
                'batch_number' => 'project-delivery:'.$delivery->id,
                'from_batch_number' => 'project-delivery:'.$delivery->id,
                'project_id' => (int) $delivery->project_id,
                'user_id' => $actor->id,
                'project_material_delivery_id' => $delivery->id,
                'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
                'reason' => $notes ?? trans_message('basic_warehouse.project_material_deliveries.cancelled'),
                'idempotency_key' => 'project-delivery-cancel-'.$delivery->id,
                'delivery_reversal' => true,
            ],
        );

        return $result['movement_in'];
    }

    private function getOrCreateTransitWarehouse(
        int $organizationId,
        int $projectId,
        User $actor,
    ): OrganizationWarehouse {
        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_EXTERNAL)
            ->where('settings->purpose', 'project_delivery_transit')
            ->first();
        if ($warehouse instanceof OrganizationWarehouse) {
            return $warehouse;
        }

        $project = Project::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($projectId);

        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'name' => trans_message('basic_warehouse.project_material_deliveries.transit_warehouse_name', [
                'project' => $project->name,
            ]),
            'code' => 'TRN-PRJ-'.$projectId,
            'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            'is_main' => false,
            'is_active' => true,
            'settings' => [
                'purpose' => 'project_delivery_transit',
                'auto_created' => true,
                'created_by_user_id' => $actor->id,
            ],
        ]);
    }
}
