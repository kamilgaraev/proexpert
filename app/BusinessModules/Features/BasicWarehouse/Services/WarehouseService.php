<?php

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\Models\Organization;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Сервис управления складом
 * Реализует WarehouseReportDataProvider для интеграции с модулями отчетов
 */
class WarehouseService implements WarehouseReportDataProvider
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Создать центральный склад для организации
     */
    public function createCentralWarehouse(int $organizationId, array $data = []): OrganizationWarehouse
    {
        $organization = Organization::findOrFail($organizationId);

        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organizationId,
            'name' => $data['name'] ?? 'Центральный склад',
            'code' => $data['code'] ?? 'CENTRAL',
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? 'Основной склад организации',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->logging->business('warehouse.central.created', [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
        ]);

        return $warehouse;
    }

    /**
     * Получить или создать центральный склад организации
     */
    public function getOrCreateCentralWarehouse(int $organizationId): OrganizationWarehouse
    {
        $warehouse = OrganizationWarehouse::where('organization_id', $organizationId)
            ->where('is_main', true)
            ->first();

        if (!$warehouse) {
            $warehouse = $this->createCentralWarehouse($organizationId);
        }

        return $warehouse;
    }

    /**
     * Получить все склады организации
     */
    public function getWarehouses(int $organizationId, bool $activeOnly = true): \Illuminate\Database\Eloquent\Collection
    {
        $query = OrganizationWarehouse::where('organization_id', $organizationId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('is_main', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Приход актива на склад
     */
    public function receiveAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        float $price,
        array $metadata = []
    ): array {
        DB::beginTransaction();
        try {
            $balance = WarehouseBalance::firstOrNew([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
            ]);

            $balance->increaseQuantity($quantity, $price);

            // Обновляем дополнительные данные
            if (isset($metadata['batch_number'])) {
                $balance->batch_number = $metadata['batch_number'];
            }
            if (isset($metadata['location_code'])) {
                $balance->location_code = $metadata['location_code'];
            }
            if (isset($metadata['expiry_date'])) {
                $balance->expiry_date = $metadata['expiry_date'];
            }

            $balance->save();

            // Создаем запись движения
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => 'receipt',
                'quantity' => $quantity,
                'price' => $price,
                'project_id' => $metadata['project_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
                'movement_date' => now(),
            ]);

            $this->logging->business('warehouse.asset.received', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'price' => $price,
                'new_balance' => $balance->available_quantity,
                'movement_id' => $movement->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'balance' => $balance,
                'movement' => $movement,
                'new_quantity' => (float)$balance->available_quantity,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Списание актива со склада
     */
    public function writeOffAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();
        try {
            $balance = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->firstOrFail();

            $balance->decreaseQuantity($quantity);

            // Создаем запись движения
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => 'write_off',
                'quantity' => $quantity,
                'price' => $balance->average_price,
                'project_id' => $metadata['project_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
                'movement_date' => now(),
            ]);

            $this->logging->business('warehouse.asset.written_off', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'reason' => $metadata['reason'] ?? null,
                'remaining_balance' => $balance->available_quantity,
                'movement_id' => $movement->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'balance' => $balance,
                'movement' => $movement,
                'remaining_quantity' => (float)$balance->available_quantity,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Перемещение актива между складами
     */
    public function transferAsset(
        int $organizationId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();
        try {
            // Получаем исходный баланс для цены
            $fromBalance = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->firstOrFail();
            
            $price = $fromBalance->average_price;

            // Списываем с исходного склада
            $fromBalance->decreaseQuantity($quantity);

            // Приходуем на целевой склад
            $toBalance = WarehouseBalance::firstOrNew([
                'organization_id' => $organizationId,
                'warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
            ]);
            
            $toBalance->increaseQuantity($quantity, $price);

            // Создаем записи движений
            $movementOut = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $fromWarehouseId,
                'material_id' => $materialId,
                'movement_type' => 'transfer_out',
                'quantity' => $quantity,
                'price' => $price,
                'to_warehouse_id' => $toWarehouseId,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
                'movement_date' => now(),
            ]);

            $movementIn = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
                'movement_type' => 'transfer_in',
                'quantity' => $quantity,
                'price' => $price,
                'from_warehouse_id' => $fromWarehouseId,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
                'movement_date' => now(),
            ]);

            $this->logging->business('warehouse.asset.transferred', [
                'organization_id' => $organizationId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'movement_out_id' => $movementOut->id,
                'movement_in_id' => $movementIn->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'from_balance' => $fromBalance,
                'to_balance' => $toBalance,
                'movement_out' => $movementOut,
                'movement_in' => $movementIn,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить остаток актива на складе
     */
    public function getAssetBalance(int $organizationId, int $warehouseId, int $materialId): ?WarehouseBalance
    {
        return WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->first();
    }

    /**
     * Получить все остатки на складе
     */
    public function getWarehouseStock(int $organizationId, int $warehouseId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('available_quantity', '>', 0)
            ->with(['material', 'warehouse']);

        // Фильтры
        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $driver = $q->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $q->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $q->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        if (isset($filters['category'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $q->where('category', $filters['category']);
            });
        }

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $query->lowStock();
        }

        return $query->orderBy('available_quantity', 'asc')->get();
    }

    /**
     * Очистить кэш склада
     */
    protected function clearWarehouseCache(int $organizationId): void
    {
        Cache::forget("warehouse_stock_{$organizationId}");
        Cache::forget("warehouse_low_stock_{$organizationId}");
    }

    // ===== Реализация WarehouseReportDataProvider =====

    /**
     * Получить данные по остаткам на складе для отчетов
     */
    public function getStockData(int $organizationId, array $filters = []): array
    {
        $query = WarehouseBalance::where('organization_id', $organizationId)
            ->where('available_quantity', '>', 0)
            ->with(['material.measurementUnit', 'warehouse']);

        // Применяем фильтры
        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $driver = $q->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $q->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $q->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        if (isset($filters['category'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $q->where('category', $filters['category']);
            });
        }

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $query->lowStock();
        }

        // Фильтр по проекту
        if (isset($filters['project_id'])) {
            $query->whereHas('projectAllocations', function ($q) use ($filters) {
                $q->where('project_id', $filters['project_id']);
            });
        }

        $balances = $query->get();

        return $balances->map(function ($balance) use ($filters) {
            $result = [
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse->name,
                'material_id' => $balance->material_id,
                'material_name' => $balance->material->name,
                'material_code' => $balance->material->code,
                'asset_type' => $balance->material->additional_properties['asset_type'] ?? 'material',
                'category' => $balance->material->category,
                'measurement_unit' => $balance->material->measurementUnit->name ?? null,
                'available_quantity' => (float)$balance->available_quantity,
                'reserved_quantity' => (float)$balance->reserved_quantity,
                'total_quantity' => $balance->total_quantity,
                'average_price' => (float)$balance->average_price,
                'total_value' => $balance->total_value,
                'min_stock_level' => (float)$balance->min_stock_level,
                'max_stock_level' => (float)$balance->max_stock_level,
                'is_low_stock' => $balance->isLowStock(),
                'location_code' => $balance->location_code,
                'last_movement_at' => $balance->last_movement_at?->toDateTimeString(),
            ];

            // Добавляем информацию о распределении по проектам
            $allocations = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation::where('warehouse_id', $balance->warehouse_id)
                ->where('material_id', $balance->material_id)
                ->with('project:id,name')
                ->get();

            if ($allocations->isNotEmpty()) {
                $result['project_allocations'] = $allocations->map(function ($allocation) {
                    return [
                        'project_id' => $allocation->project_id,
                        'project_name' => $allocation->project->name,
                        'allocated_quantity' => (float)$allocation->allocated_quantity,
                    ];
                })->toArray();

                $result['allocated_total'] = $allocations->sum('allocated_quantity');
                $result['unallocated_quantity'] = (float)$balance->available_quantity - $result['allocated_total'];
            } else {
                $result['project_allocations'] = [];
                $result['allocated_total'] = 0;
                $result['unallocated_quantity'] = (float)$balance->available_quantity;
            }

            return $result;
        })->toArray();
    }

    /**
     * Получить данные по движению активов для отчетов
     */
    public function getMovementsData(int $organizationId, array $filters = []): array
    {
        $query = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('organization_id', $organizationId)
            ->with(['material.measurementUnit', 'warehouse', 'project', 'user']);

        // Применяем фильтры
        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('movement_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('movement_date', '<=', $filters['date_to']);
        }

        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $driver = $q->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $q->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $q->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        $movements = $query->orderBy('movement_date', 'desc')->get();

        return $movements->map(function ($movement) {
            return [
                'movement_id' => $movement->id,
                'movement_type' => $movement->movement_type,
                'warehouse_id' => $movement->warehouse_id,
                'warehouse_name' => $movement->warehouse->name,
                'material_id' => $movement->material_id,
                'material_name' => $movement->material->name,
                'material_code' => $movement->material->code,
                'quantity' => (float)$movement->quantity,
                'price' => (float)$movement->price,
                'total_value' => (float)$movement->quantity * (float)$movement->price,
                'measurement_unit' => $movement->material->measurementUnit->name ?? null,
                'project_id' => $movement->project_id,
                'project_name' => $movement->project->name ?? null,
                'user_name' => $movement->user->name ?? null,
                'document_number' => $movement->document_number,
                'reason' => $movement->reason,
                'movement_date' => $movement->movement_date->toDateTimeString(),
            ];
        })->toArray();
    }

    /**
     * Получить данные инвентаризации для отчетов
     */
    public function getInventoryData(int $organizationId, array $filters = []): array
    {
        $query = \App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct::where('organization_id', $organizationId)
            ->with(['warehouse', 'creator', 'items.material']);

        // Применяем фильтры
        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('inventory_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('inventory_date', '<=', $filters['date_to']);
        }

        $acts = $query->orderBy('inventory_date', 'desc')->get();

        return $acts->map(function ($act) {
            return [
                'act_id' => $act->id,
                'act_number' => $act->act_number,
                'warehouse_id' => $act->warehouse_id,
                'warehouse_name' => $act->warehouse->name,
                'status' => $act->status,
                'inventory_date' => $act->inventory_date->toDateString(),
                'created_by' => $act->creator->name,
                'items_count' => $act->items->count(),
                'discrepancies_count' => $act->items->filter(fn($item) => $item->hasDiscrepancy())->count(),
                'total_difference_value' => $act->items->sum('total_value'),
                'started_at' => $act->started_at?->toDateTimeString(),
                'completed_at' => $act->completed_at?->toDateTimeString(),
                'approved_at' => $act->approved_at?->toDateTimeString(),
                'items' => $act->items->map(function ($item) {
                    return [
                        'material_id' => $item->material_id,
                        'material_name' => $item->material->name,
                        'expected_quantity' => (float)$item->expected_quantity,
                        'actual_quantity' => (float)$item->actual_quantity,
                        'difference' => (float)$item->difference,
                        'unit_price' => (float)$item->unit_price,
                        'total_value' => (float)$item->total_value,
                        'has_discrepancy' => $item->hasDiscrepancy(),
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    /**
     * Получить данные аналитики оборачиваемости (заглушка для BasicWarehouse)
     */
    public function getTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        return [
            'error' => 'Аналитика оборачиваемости доступна только в AdvancedWarehouse',
        ];
    }

    /**
     * Получить прогноз потребности (заглушка для BasicWarehouse)
     */
    public function getForecastData(int $organizationId, array $filters = []): array
    {
        return [
            'error' => 'Прогнозирование доступно только в AdvancedWarehouse',
        ];
    }

    /**
     * Получить ABC/XYZ анализ (заглушка для BasicWarehouse)
     */
    public function getAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        return [
            'error' => 'ABC/XYZ анализ доступен только в AdvancedWarehouse',
        ];
    }
}

