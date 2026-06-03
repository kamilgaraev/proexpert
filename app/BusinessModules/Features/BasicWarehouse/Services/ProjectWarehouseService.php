<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Project;
use App\Models\User;
use DomainException;

use function trans_message;

final class ProjectWarehouseService
{
    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {
    }

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
            'name' => 'Объектовый склад: ' . $project->name,
            'code' => 'PRJ-' . $projectId,
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
    ): WarehouseMovement {
        if (!$delivery->warehouse_id) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.source_required'));
        }

        $projectWarehouse = $this->getOrCreateProjectWarehouse(
            (int) $delivery->organization_id,
            (int) $delivery->project_id,
            $actor
        );

        $result = $this->warehouseService->writeOffAsset(
            (int) $delivery->organization_id,
            (int) $delivery->warehouse_id,
            (int) $delivery->material_id,
            $quantity,
            [
                'project_id' => (int) $delivery->project_id,
                'user_id' => $actor->id,
                'related_user_id' => $responsibleUserId,
                'project_material_delivery_id' => $delivery->id,
                'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
                'reason' => $notes ?? trans_message('basic_warehouse.project_material_deliveries.shipped'),
            ]
        );

        /** @var WarehouseMovement $movement */
        $movement = $result['movement'];
        $movement->forceFill([
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'to_warehouse_id' => $projectWarehouse->id,
            'related_user_id' => $responsibleUserId,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
        ])->save();

        return $movement->refresh();
    }

    public function receiveOnProject(
        ProjectMaterialDelivery $delivery,
        User $actor,
        float $quantity,
        ?string $notes
    ): WarehouseMovement {
        $projectWarehouse = $delivery->project_warehouse_id
            ? OrganizationWarehouse::query()
                ->where('organization_id', (int) $delivery->organization_id)
                ->where('warehouse_type', OrganizationWarehouse::TYPE_PROJECT)
                ->findOrFail((int) $delivery->project_warehouse_id)
            : $this->getOrCreateProjectWarehouse((int) $delivery->organization_id, (int) $delivery->project_id, $actor);

        $price = (float) ($delivery->outboundMovement?->price ?? $delivery->material?->default_price ?? 0);

        $result = $this->warehouseService->receiveAsset(
            (int) $delivery->organization_id,
            (int) $projectWarehouse->id,
            (int) $delivery->material_id,
            $quantity,
            $price,
            [
                'project_id' => (int) $delivery->project_id,
                'user_id' => $actor->id,
                'project_material_delivery_id' => $delivery->id,
                'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
                'reason' => $notes ?? trans_message('basic_warehouse.project_material_deliveries.received'),
            ]
        );

        /** @var WarehouseMovement $movement */
        $movement = $result['movement'];
        $movement->forceFill([
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'from_warehouse_id' => $delivery->warehouse_id,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
        ])->save();

        return $movement->refresh();
    }
}
