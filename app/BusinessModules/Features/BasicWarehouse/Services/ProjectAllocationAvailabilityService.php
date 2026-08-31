<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use Illuminate\Support\Collection;

final class ProjectAllocationAvailabilityService
{
    public function outstandingForAllocation(
        int $organizationId,
        WarehouseProjectAllocation $allocation,
    ): float {
        return (float) $this->outstandingForAllocations(
            $organizationId,
            collect([$allocation]),
        )->get($allocation->id, 0);
    }

    public function outstandingByMaterial(
        int $organizationId,
        int $warehouseId,
        array $materialIds,
    ): Collection {
        $allocations = WarehouseProjectAllocation::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('material_id', $materialIds)
            ->get(['id', 'material_id', 'allocated_quantity']);

        if ($allocations->isEmpty()) {
            return collect();
        }

        $outstandingByAllocation = $this->outstandingForAllocations($organizationId, $allocations);

        return $allocations
            ->groupBy('material_id')
            ->map(static fn (Collection $materialAllocations): float => (float) $materialAllocations->sum(
                static fn (WarehouseProjectAllocation $allocation): float => (float) (
                    $outstandingByAllocation->get($allocation->id) ?? 0
                ),
            ));
    }

    public function outstandingForAllocations(int $organizationId, Collection $allocations): Collection
    {
        if ($allocations->isEmpty()) {
            return collect();
        }

        $departedByAllocation = ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->whereIn('warehouse_project_allocation_id', $allocations->pluck('id'))
            ->selectRaw(
                'warehouse_project_allocation_id, SUM(CASE WHEN status = ? THEN accepted_quantity ELSE shipped_quantity END) AS departed_quantity',
                [ProjectMaterialDeliveryStatusEnum::CANCELLED->value],
            )
            ->groupBy('warehouse_project_allocation_id')
            ->pluck('departed_quantity', 'warehouse_project_allocation_id');

        return $allocations->mapWithKeys(
            static function (WarehouseProjectAllocation $allocation) use ($departedByAllocation): array {
                $allocated = (float) $allocation->allocated_quantity;
                $departed = min(
                    $allocated,
                    (float) ($departedByAllocation->get($allocation->id) ?? 0),
                );

                return [$allocation->id => max(0.0, $allocated - $departed)];
            },
        );
    }
}
