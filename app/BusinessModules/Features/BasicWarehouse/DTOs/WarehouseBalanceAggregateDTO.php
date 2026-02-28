<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\DTOs;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Models\Material;

final readonly class WarehouseBalanceAggregateDTO
{
    public function __construct(
        public int $materialId,
        public int $warehouseId,
        public float $availableQuantity,
        public float $reservedQuantity,
        public float $averagePrice,
        public float $totalValue,
        public ?string $lastMovementAt,
        public ?Material $material = null,
        public ?OrganizationWarehouse $warehouse = null,
    ) {}

    /**
     * Получить количество материала, распределенного по проектам
     * (сколько уже "зарезервировано" за проектами)
     */
    public function getAllocatedQuantity(): float
    {
        return (float)WarehouseProjectAllocation::where('warehouse_id', $this->warehouseId)
            ->where('material_id', $this->materialId)
            ->sum('allocated_quantity');
    }

    /**
     * Получить количество доступное для распределения
     * (доступное на складе минус уже распределенное по проектам)
     */
    public function getAvailableForAllocation(): float
    {
        $allocated = $this->getAllocatedQuantity();
        return max(0, (float)$this->availableQuantity - $allocated);
    }

    /**
     * Проверить, можно ли распределить указанное количество
     */
    public function canAllocate(float $quantity): bool
    {
        return $quantity <= $this->getAvailableForAllocation();
    }

    /**
     * Проверить, достаточно ли материала для распределения
     * (с подробной информацией для ошибки)
     */
    public function checkAllocationAvailability(float $requestedQuantity): array
    {
        $allocated = $this->getAllocatedQuantity();
        $availableForAllocation = $this->getAvailableForAllocation();

        return [
            'can_allocate' => $requestedQuantity <= $availableForAllocation,
            'warehouse_quantity' => (float)$this->availableQuantity,
            'already_allocated' => $allocated,
            'available_for_allocation' => $availableForAllocation,
            'requested_quantity' => $requestedQuantity,
            'shortage' => max(0, $requestedQuantity - $availableForAllocation),
        ];
    }

    public function toArray(): array
    {
        return [
            'material_id' => $this->materialId,
            'warehouse_id' => $this->warehouseId,
            'available_quantity' => $this->availableQuantity,
            'reserved_quantity' => $this->reservedQuantity,
            'average_price' => $this->averagePrice,
            'total_value' => $this->totalValue,
            'last_movement_at' => $this->lastMovementAt,
            'material' => $this->material?->toArray(),
            'warehouse' => $this->warehouse?->toArray(),
        ];
    }
}
