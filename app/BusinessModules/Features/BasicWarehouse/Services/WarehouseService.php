<?php

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\AutoReorderRule;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\Models\Organization;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Models\Supplier;
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
    /**
     * Приход актива на склад (Партионный учет)
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
            // Ищем существующую партию с такой же ценой и параметрами
            // (Стратегия: смешиваем партии только если они абсолютно идентичны по цене и срокам)
            $query = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('unit_price', $price);

            if (isset($metadata['batch_number'])) {
                $query->where('batch_number', $metadata['batch_number']);
            } else {
                $query->whereNull('batch_number');
            }

            if (isset($metadata['expiry_date'])) {
                $query->where('expiry_date', $metadata['expiry_date']);
            } else {
                $query->whereNull('expiry_date');
            }
            
            // Если включено адресное хранение, то разделяем и по местам
            if (isset($metadata['location_code'])) {
                 $query->where('location_code', $metadata['location_code']);
            }

            $balance = $query->first();

            if ($balance) {
                // Если партия найдена - увеличиваем количество
                $balance->available_quantity += $quantity;
                $balance->last_movement_at = now();
                $balance->save();
            } else {
                // Если нет - создаем новую партию
                $balance = WarehouseBalance::create([
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                    'available_quantity' => $quantity,
                    'unit_price' => $price,
                    'batch_number' => $metadata['batch_number'] ?? null,
                    'expiry_date' => $metadata['expiry_date'] ?? null,
                    'location_code' => $metadata['location_code'] ?? null,
                    'created_at' => now(), // Важно для FIFO
                    'last_movement_at' => now(),
                ]);
            }

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
                'batch_id' => $balance->id,
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
     * Списание актива со склада (FIFO стратегия)
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
            // Получаем все партии с доступным количеством, сортируем по дате создания (FIFO)
            // (или по сроку годности FEFO, если есть)
            $batches = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('available_quantity', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
                ->lockForUpdate()
                ->get();

            $totalAvailable = $batches->sum('available_quantity');

            if ($totalAvailable < $quantity) {
                throw new \InvalidArgumentException(
                    "Недостаточно активов на складе. Доступно: {$totalAvailable}, запрошено: {$quantity}"
                );
            }

            $remainingToWriteOff = $quantity;
            $writeOffDetails = []; // Для истории, с каких партий списали
            $totalCost = 0;

            foreach ($batches as $batch) {
                if ($remainingToWriteOff <= 0) break;

                $takeFromBatch = min($batch->available_quantity, $remainingToWriteOff);
                
                $batch->decreaseQuantity($takeFromBatch);
                
                $remainingToWriteOff -= $takeFromBatch;
                $writeOffDetails[] = [
                    'balance_id' => $batch->id,
                    'quantity' => $takeFromBatch,
                    'unit_price' => $batch->unit_price,
                    'batch_number' => $batch->batch_number
                ];
                
                $totalCost += ($takeFromBatch * $batch->unit_price);
            }

            // Рассчитываем среднюю цену списания для движения
            $avgWriteOffPrice = $quantity > 0 ? $totalCost / $quantity : 0;

            // Создаем запись движения
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => 'write_off',
                'quantity' => $quantity,
                'price' => $avgWriteOffPrice, // Фиксируем среднюю цену списания
                'project_id' => $metadata['project_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => array_merge($metadata, ['batches_source' => $writeOffDetails]),
                'movement_date' => now(),
            ]);

            $this->logging->business('warehouse.asset.written_off', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'avg_price' => $avgWriteOffPrice,
                'batches_count' => count($writeOffDetails),
                'movement_id' => $movement->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'movement' => $movement,
                'write_off_details' => $writeOffDetails,
                'remaining_total_quantity' => $totalAvailable - $quantity,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Перемещение актива между складами (с поддержкой FIFO)
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
            // 1. Списываем с исходного склада по FIFO
            $sourceBatches = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('material_id', $materialId)
                ->where('available_quantity', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
                ->lockForUpdate()
                ->get();

            $totalAvailable = $sourceBatches->sum('available_quantity');

            if ($totalAvailable < $quantity) {
                 throw new \InvalidArgumentException(
                    "Недостаточно активов для перемещения. Доступно: {$totalAvailable}, запрошено: {$quantity}"
                );
            }

            $remainingToTransfer = $quantity;
            $transferCost = 0;
            $sourceBatchDetails = [];

            foreach ($sourceBatches as $batch) {
                if ($remainingToTransfer <= 0) break;

                $takeFromBatch = min($batch->available_quantity, $remainingToTransfer);
                
                $batch->decreaseQuantity($takeFromBatch);
                
                $remainingToTransfer -= $takeFromBatch;
                $transferCost += ($takeFromBatch * $batch->unit_price);
                
                $sourceBatchDetails[] = [
                    'source_batch_id' => $batch->id,
                    'quantity' => $takeFromBatch,
                    'unit_price' => $batch->unit_price,
                ];
            }

            // Средняя цена перемещаемой партии
            $transferPrice = $quantity > 0 ? $transferCost / $quantity : 0;

            // 2. Приходуем на целевой склад (как одну партию с усредненной ценой)
            // (Можно было бы переносить партиями, но это сильно усложнит логику, обычно при перемещении принимают по учетной стоимости)
            $targetResult = $this->receiveAsset(
                $organizationId,
                $toWarehouseId,
                $materialId,
                $quantity,
                $transferPrice,
                array_merge($metadata, [
                    'reason' => 'Перемещение со склада ' . $fromWarehouseId,
                    'is_transfer' => true
                ])
            );
            
            // Удаляем лишнее движение, которое создал receiveAsset (нам нужны специфичные типы transfer_in/out)
            // Или просто обновим его тип и связи
            $movementIn = $targetResult['movement'];
            $movementIn->update([
                'movement_type' => 'transfer_in',
                'from_warehouse_id' => $fromWarehouseId,
            ]);

            // 3. Создаем движение расхода с исходного склада
            $movementOut = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $fromWarehouseId,
                'material_id' => $materialId,
                'movement_type' => 'transfer_out',
                'quantity' => $quantity,
                'price' => $transferPrice,
                'to_warehouse_id' => $toWarehouseId,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => array_merge($metadata, ['source_batches' => $sourceBatchDetails]),
                'movement_date' => now(),
            ]);

            $this->logging->business('warehouse.asset.transferred', [
                'organization_id' => $organizationId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'avg_price' => $transferPrice,
                'movement_out_id' => $movementOut->id,
                'movement_in_id' => $movementIn->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'movement_out' => $movementOut,
                'movement_in' => $movementIn,
                'avg_price' => $transferPrice,
                'source_details' => $sourceBatchDetails
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить остаток актива на складе (Агрегированный)
     */
    public function getAssetBalance(int $organizationId, int $warehouseId, int $materialId): ?WarehouseBalance
    {
        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->get();

        if ($batches->isEmpty()) {
            return null;
        }

        // Создаем виртуальный объект баланса с агрегированными данными
        $totalQty = $batches->sum('available_quantity');
        $totalReserved = $batches->sum('reserved_quantity');
        
        $totalValue = $batches->sum(fn($b) => $b->available_quantity * $b->unit_price);
        $avgPrice = $totalQty > 0 ? $totalValue / $totalQty : 0;
        
        // Берем первый батч как основу для метаданных (материал, склад и т.д.)
        $virtualBalance = $batches->first()->replicate();
        $virtualBalance->exists = true; // Чтобы Laravel думал, что модель существует (нужно для связей)
        $virtualBalance->id = $batches->first()->id; // ID первого батча (условно)
        $virtualBalance->isVirtual = true; // Запрещаем сохранение
        
        $virtualBalance->available_quantity = $totalQty;
        $virtualBalance->reserved_quantity = $totalReserved;
        $virtualBalance->unit_price = $avgPrice; // Здесь unit_price выступает как средняя цена
        
        // Добавляем инфу о дате последнего движения (максимальная из батчей)
        $virtualBalance->last_movement_at = $batches->max('last_movement_at');
        
        return $virtualBalance;
    }

    /**
     * Получить все остатки на складе (Агрегированные)
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

        $allBatches = $query->get();
        
        // Группируем по материалам
        $grouped = $allBatches->groupBy('material_id');
        
        $aggregatedCollection = new \Illuminate\Database\Eloquent\Collection();
        
        foreach ($grouped as $materialId => $batches) {
            $totalQty = $batches->sum('available_quantity');
            $totalReserved = $batches->sum('reserved_quantity');
            
            $totalValue = $batches->sum(fn($b) => $b->available_quantity * $b->unit_price);
            $avgPrice = $totalQty > 0 ? $totalValue / $totalQty : 0;
            
            $virtualBalance = $batches->first()->replicate();
            $virtualBalance->exists = true;
            $virtualBalance->id = $batches->first()->id;
            $virtualBalance->isVirtual = true; // Запрещаем сохранение
            $virtualBalance->available_quantity = $totalQty;
            $virtualBalance->reserved_quantity = $totalReserved;
            $virtualBalance->unit_price = $avgPrice;
            
             // Загружаем связи, если они были загружены в оригинале
            $virtualBalance->setRelation('material', $batches->first()->material);
            $virtualBalance->setRelation('warehouse', $batches->first()->warehouse);
            
            $aggregatedCollection->push($virtualBalance);
        }
        
        return $aggregatedCollection;
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
     * Получить данные по остаткам на складе для отчетов (Агрегированные)
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

        // Фильтр по проекту (для партионного учета сложнее, так как распределение - отдельная таблица)
        // Но аллокации не привязаны к партиям, они привязаны к "Склад + Материал"
        // Поэтому фильтрация по аллокациям работает на уровне материала
        if (isset($filters['project_id'])) {
            $query->whereHas('projectAllocations', function ($q) use ($filters) {
                $q->where('project_id', $filters['project_id']);
            });
        }

        $allBatches = $query->get();
        
        // Группируем по материалам
        $grouped = $allBatches->groupBy('material_id');
        
        $resultData = [];
        
        foreach ($grouped as $materialId => $batches) {
            $totalQty = $batches->sum('available_quantity');
            $totalReserved = $batches->sum('reserved_quantity');
            $totalValue = $batches->sum(fn($b) => $b->available_quantity * $b->unit_price);
            $avgPrice = $totalQty > 0 ? $totalValue / $totalQty : 0;
            
            $first = $batches->first();
            
            $item = [
                'warehouse_id' => $first->warehouse_id,
                'warehouse_name' => $first->warehouse->name,
                'material_id' => $first->material_id,
                'material_name' => $first->material->name,
                'material_code' => $first->material->code,
                'asset_type' => $first->material->additional_properties['asset_type'] ?? 'material',
                'category' => $first->material->category,
                'measurement_unit' => $first->material->measurementUnit->name ?? null,
                'available_quantity' => (float)$totalQty,
                'reserved_quantity' => (float)$totalReserved,
                'total_quantity' => $totalQty + $totalReserved,
                'average_price' => (float)$avgPrice,
                'total_value' => $totalValue, // Точная сумма цен всех партий
                'min_stock_level' => (float)$first->min_stock_level,
                'max_stock_level' => (float)$first->max_stock_level,
                'is_low_stock' => $first->min_stock_level > 0 && $totalQty <= $first->min_stock_level,
                'location_code' => $batches->pluck('location_code')->filter()->unique()->implode(', '),
                'last_movement_at' => $batches->max('last_movement_at')?->toDateTimeString(),
            ];

            // Добавляем информацию о распределении по проектам (она общая для всех партий)
            $allocations = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation::where('warehouse_id', $first->warehouse_id)
                ->where('material_id', $first->material_id)
                ->with('project:id,name')
                ->get();

            if ($allocations->isNotEmpty()) {
                $item['project_allocations'] = $allocations->map(function ($allocation) {
                    return [
                        'project_id' => $allocation->project_id,
                        'project_name' => $allocation->project->name,
                        'allocated_quantity' => (float)$allocation->allocated_quantity,
                    ];
                })->toArray();

                $item['allocated_total'] = $allocations->sum('allocated_quantity');
                $item['unallocated_quantity'] = (float)$totalQty - $item['allocated_total'];
            } else {
                $item['project_allocations'] = [];
                $item['allocated_total'] = 0;
                $item['unallocated_quantity'] = (float)$totalQty;
            }
            
            $resultData[] = $item;
        }

        return $resultData;
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
     * Получить данные аналитики оборачиваемости
     */
    public function getTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->subMonth();
        $dateTo = $filters['date_to'] ?? now();
        
        // Получаем движения за период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->with(['material'])
            ->get();
        
        // Группируем по материалам
        $assetAnalytics = [];
        $materialIds = $movements->pluck('material_id')->unique();
        
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Расход за период (write_off)
            $consumption = $materialMovements
                ->where('movement_type', 'write_off')
                ->sum('quantity');
            
            // Средний остаток (упрощенно - текущий остаток)
            $balance = WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->first();
            
            $averageStock = $balance ? (float)$balance->available_quantity : 0;
            
            // Коэффициент оборачиваемости
            $turnoverRate = $averageStock > 0 ? $consumption / $averageStock : 0;
            
            // Период оборачиваемости в днях
            $days = $dateFrom->diffInDays($dateTo);
            $turnoverDays = $turnoverRate > 0 ? $days / $turnoverRate : 0;
            
            // ABC категория (упрощенно - по потреблению)
            $category = $turnoverRate > 2 ? 'A' : ($turnoverRate > 0.5 ? 'B' : 'C');
            
            $assetAnalytics[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'average_stock' => $averageStock,
                'consumption' => (float)$consumption,
                'turnover_rate' => round($turnoverRate, 2),
                'turnover_days' => round($turnoverDays, 0),
                'category' => $category,
            ];
        }
        
        // Сортируем по оборачиваемости
        usort($assetAnalytics, fn($a, $b) => $b['turnover_rate'] <=> $a['turnover_rate']);
        
        return [
            'period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'days' => $dateFrom->diffInDays($dateTo),
            ],
            'assets' => $assetAnalytics,
            'summary' => [
                'total_assets_analyzed' => count($assetAnalytics),
                'average_turnover_rate' => count($assetAnalytics) > 0 
                    ? round(collect($assetAnalytics)->avg('turnover_rate'), 2) 
                    : 0,
                'slow_moving_count' => collect($assetAnalytics)->where('category', 'C')->count(),
                'fast_moving_count' => collect($assetAnalytics)->where('category', 'A')->count(),
            ],
        ];
    }

    /**
     * Получить прогноз потребности в материалах
     */
    public function getForecastData(int $organizationId, array $filters = []): array
    {
        $horizonDays = $filters['horizon_days'] ?? 90;
        $historicalDays = 90; // Анализируем последние 90 дней
        
        $dateFrom = now()->subDays($historicalDays);
        $dateTo = now();
        
        // Получаем движения за исторический период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->with(['material'])
            ->get();
        
        $forecasts = [];
        $materialIds = $movements->pluck('material_id')->unique();
        
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Простой линейный прогноз: средний расход в день * горизонт
            $totalConsumption = $materialMovements->sum('quantity');
            $averageDailyConsumption = $totalConsumption / $historicalDays;
            $predictedConsumption = $averageDailyConsumption * $horizonDays;
            
            // Текущий остаток (сумма по всем партиям)
            $currentStock = WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->sum('available_quantity');
            
            // Дата исчерпания запасов
            $daysUntilStockOut = $averageDailyConsumption > 0 
                ? $currentStock / $averageDailyConsumption 
                : 999999;
            
            // Рекомендуемое количество заказа (покрытие на 30 дней)
            $recommendedOrderQuantity = max(0, $averageDailyConsumption * 30 - $currentStock);
            
            // Уровень уверенности (упрощенно - на основе стабильности потребления)
            $consumptionVariance = $this->calculateVariance(
                $materialMovements->pluck('quantity')->toArray()
            );
            $confidence = max(50, min(95, 100 - ($consumptionVariance * 10)));
            
            $forecasts[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'current_stock' => $currentStock,
                'average_daily_consumption' => round($averageDailyConsumption, 2),
                'predicted_consumption' => round($predictedConsumption, 2),
                'recommended_order_quantity' => round($recommendedOrderQuantity, 2),
                'estimated_stock_out_date' => $daysUntilStockOut < $horizonDays 
                    ? now()->addDays((int)$daysUntilStockOut)->toDateString()
                    : null,
                'days_until_stock_out' => min((int)$daysUntilStockOut, $horizonDays),
                'confidence' => (int)$confidence,
                'forecast_method' => 'linear_average',
            ];
        }
        
        // Сортируем по срочности
        usort($forecasts, fn($a, $b) => $a['days_until_stock_out'] <=> $b['days_until_stock_out']);
        
        // Разделяем по приоритетам
        $immediateOrders = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] < 7)->values()->toArray();
        $plannedOrders = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] >= 7 && $f['days_until_stock_out'] < 30)->values()->toArray();
        $excessiveStock = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] > 180)->values()->toArray();
        
        return [
            'forecast_period' => [
                'start_date' => now()->toDateString(),
                'end_date' => now()->addDays($horizonDays)->toDateString(),
                'horizon_days' => $horizonDays,
                'historical_days' => $historicalDays,
            ],
            'forecasts' => $forecasts,
            'recommendations' => [
                'immediate_orders' => $immediateOrders,
                'planned_orders' => $plannedOrders,
                'excessive_stock' => $excessiveStock,
            ],
            'summary' => [
                'total_assets_forecasted' => count($forecasts),
                'immediate_attention_required' => count($immediateOrders),
                'planned_orders_required' => count($plannedOrders),
                'excessive_stock_count' => count($excessiveStock),
            ],
        ];
    }

    /**
     * Получить ABC/XYZ анализ запасов
     */
    public function getAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->subYear();
        $dateTo = $filters['date_to'] ?? now();
        
        // Получаем движения за период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->with(['material'])
            ->get();
        
        $assetAnalysis = [];
        $materialIds = $movements->pluck('material_id')->unique();
        $totalValue = 0;
        
        // Первый проход: рассчитываем стоимость потребления для каждого актива
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Стоимость потребления за период
            $consumptionValue = $materialMovements->sum(function($m) {
                return (float)$m->quantity * (float)$m->price;
            });
            
            // Коэффициент вариации для XYZ
            $quantities = $materialMovements->pluck('quantity')->toArray();
            $variance = $this->calculateVariance($quantities);
            
            $assetAnalysis[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'total_value' => $consumptionValue,
                'consumption_variance' => $variance,
            ];
            
            $totalValue += $consumptionValue;
        }
        
        // Сортируем по стоимости для ABC анализа
        usort($assetAnalysis, fn($a, $b) => $b['total_value'] <=> $a['total_value']);
        
        // Второй проход: присваиваем ABC категории (правило Парето)
        $cumulativePercent = 0;
        foreach ($assetAnalysis as &$asset) {
            $asset['value_percent'] = $totalValue > 0 ? ($asset['total_value'] / $totalValue) * 100 : 0;
            $cumulativePercent += $asset['value_percent'];
            
            // ABC категории: A=80%, B=15%, C=5%
            if ($cumulativePercent <= 80) {
                $asset['abc_category'] = 'A';
            } elseif ($cumulativePercent <= 95) {
                $asset['abc_category'] = 'B';
            } else {
                $asset['abc_category'] = 'C';
            }
            
            // XYZ категории по коэффициенту вариации
            if ($asset['consumption_variance'] < 0.1) {
                $asset['xyz_category'] = 'X';
            } elseif ($asset['consumption_variance'] < 0.25) {
                $asset['xyz_category'] = 'Y';
            } else {
                $asset['xyz_category'] = 'Z';
            }
            
            $asset['combined_category'] = $asset['abc_category'] . $asset['xyz_category'];
            
            // Рекомендации по категориям
            $asset['recommendation'] = $this->getAbcXyzRecommendation($asset['combined_category']);
        }
        
        // Подсчет распределения
        $abcDistribution = [
            'A' => ['count' => collect($assetAnalysis)->where('abc_category', 'A')->count(), 'value_percent' => 80],
            'B' => ['count' => collect($assetAnalysis)->where('abc_category', 'B')->count(), 'value_percent' => 15],
            'C' => ['count' => collect($assetAnalysis)->where('abc_category', 'C')->count(), 'value_percent' => 5],
        ];
        
        $xyzDistribution = [
            'X' => ['count' => collect($assetAnalysis)->where('xyz_category', 'X')->count(), 'stability' => 'high'],
            'Y' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Y')->count(), 'stability' => 'medium'],
            'Z' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Z')->count(), 'stability' => 'low'],
        ];
        
        return [
            'analysis_period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'abc_distribution' => $abcDistribution,
            'xyz_distribution' => $xyzDistribution,
            'assets' => $assetAnalysis,
            'recommendations' => [
                'AX' => 'Критические товары со стабильным спросом - строгий контроль, минимальные запасы, частые поставки',
                'AY' => 'Критические товары со средней стабильностью - повышенные страховые запасы',
                'AZ' => 'Критические товары с нестабильным спросом - максимальные страховые запасы, анализ причин',
                'BX' => 'Важные товары со стабильным спросом - стандартный контроль, средние запасы',
                'BY' => 'Важные товары со средней стабильностью - средние страховые запасы',
                'BZ' => 'Важные товары с нестабильным спросом - повышенные страховые запасы',
                'CX' => 'Малоценные товары со стабильным спросом - упрощенный контроль, закупка большими партиями',
                'CY' => 'Малоценные товары со средней стабильностью - стандартные запасы',
                'CZ' => 'Малоценные товары с нестабильным спросом - минимальный контроль, закупка по мере необходимости',
            ],
            'summary' => [
                'total_assets_analyzed' => count($assetAnalysis),
                'total_consumption_value' => round($totalValue, 2),
                'critical_assets_count' => $abcDistribution['A']['count'],
                'stable_assets_count' => $xyzDistribution['X']['count'],
            ],
        ];
    }

    /**
     * Зарезервировать активы для проекта
     */
    /**
     * Зарезервировать количество актива на складе (FIFO)
     * Обновляет балансы партий, перенося из available в reserved
     */
    public function reserveQuantity(int $organizationId, int $warehouseId, int $materialId, float $quantity): void
    {
        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('available_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()
            ->get();

        $totalAvailable = $batches->sum('available_quantity');

        if ($totalAvailable < $quantity) {
            throw new \InvalidArgumentException(
                "Недостаточно активов для резервирования. Доступно: {$totalAvailable}, запрошено: {$quantity}"
            );
        }

        $remainingToReserve = $quantity;

        foreach ($batches as $batch) {
            if ($remainingToReserve <= 0) break;

            $takeFromBatch = min($batch->available_quantity, $remainingToReserve);
            
            $batch->reserve($takeFromBatch);
            
            $remainingToReserve -= $takeFromBatch;
        }
    }

    /**
     * Снять резервирование количества (освободить FIFO/LIFO или просто наличие)
     */
    public function unreserveQuantity(int $organizationId, int $warehouseId, int $materialId, float $quantity): void
    {
        // Ищем партии где есть резерв
        // Снимаем резерв с тех партий, где он есть. Порядок не так важен, но логично снимать с тех, 
        // которые скорее всего были зарезервированы последними (LIFO) или первыми (FIFO).
        // Давайте снимать с тех, у кого expiry_date раньше (чтобы освободить "скоропортящиеся" для продажи) => FIFO
        
        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('reserved_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()
            ->get();
            
        $totalReserved = $batches->sum('reserved_quantity');
        
        if ($totalReserved < $quantity) {
             throw new \InvalidArgumentException(
                "Недостаточно зарезервированных активов. Зарезервировано: {$totalReserved}, запрошено: {$quantity}"
            );
        }
        
        $remainingToUnreserve = $quantity;
        
        foreach ($batches as $batch) {
            if ($remainingToUnreserve <= 0) break;
            
            $takeFromBatch = min($batch->reserved_quantity, $remainingToUnreserve);
            
            $batch->unreserve($takeFromBatch);
            
            $remainingToUnreserve -= $takeFromBatch;
        }
    }

    /**
     * Зарезервировать активы для проекта (создает запись AssetReservation)
     */
    public function reserveAssets(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();
        
        try {
            // Проверяем доступность (через getWarehouseStock или напрямую)
            // Но reserveQuantity сама проверит и выбросит исключение
            
            // Создаем резервацию
            $expiresAt = isset($metadata['expires_hours']) 
                ? now()->addHours($metadata['expires_hours'])
                : now()->addHours(24);
            
            $reservation = AssetReservation::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'project_id' => $metadata['project_id'] ?? null,
                'reserved_by' => $metadata['user_id'] ?? 1,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
            ]);
            
            // Резервируем в балансах (партии)
            $this->reserveQuantity($organizationId, $warehouseId, $materialId, $quantity);
            
            $this->logging->business('warehouse.asset.reserved', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'reservation_id' => $reservation->id,
            ]);
            
            DB::commit();
            
            // Получаем остаток для возврата (агрегированный)
            $balance = $this->getAssetBalance($organizationId, $warehouseId, $materialId);
            
            return [
                'reserved' => true,
                'reservation_id' => $reservation->id,
                'quantity' => (float)$quantity,
                'expires_at' => $expiresAt->toDateTimeString(),
                'remaining_available' => $balance ? (float)$balance->available_quantity : 0,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Снять резервирование
     */
    public function unreserveAssets(int $reservationId): bool
    {
        DB::beginTransaction();
        
        try {
            $reservation = AssetReservation::where('id', $reservationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            
            // Возвращаем количество в доступные (распределяем по партиям)
            $this->unreserveQuantity(
                $reservation->organization_id, 
                $reservation->warehouse_id, 
                $reservation->material_id, 
                $reservation->quantity
            );
            
            // Обновляем статус резервации
            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            
            $this->logging->business('warehouse.asset.unreserved', [
                'reservation_id' => $reservationId,
                'organization_id' => $reservation->organization_id,
                'quantity' => $reservation->quantity,
            ]);
            
            DB::commit();
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать правило автоматического пополнения
     */
    public function createAutoReorderRule(
        int $organizationId,
        int $materialId,
        array $ruleData
    ): array {
        $warehouseId = $ruleData['warehouse_id'];
        
        // Проверяем существование правила
        $existingRule = AutoReorderRule::where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->first();
        
        if ($existingRule) {
            // Обновляем существующее правило
            $existingRule->update([
                'min_stock' => $ruleData['min_stock'],
                'max_stock' => $ruleData['max_stock'],
                'reorder_point' => $ruleData['reorder_point'],
                'reorder_quantity' => $ruleData['reorder_quantity'],
                'default_supplier_id' => $ruleData['default_supplier_id'] ?? null,
                'is_active' => $ruleData['is_active'] ?? true,
                'notes' => $ruleData['notes'] ?? null,
            ]);
            
            $rule = $existingRule;
            $action = 'updated';
        } else {
            // Создаем новое правило
            $rule = AutoReorderRule::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'min_stock' => $ruleData['min_stock'],
                'max_stock' => $ruleData['max_stock'],
                'reorder_point' => $ruleData['reorder_point'],
                'reorder_quantity' => $ruleData['reorder_quantity'],
                'default_supplier_id' => $ruleData['default_supplier_id'] ?? null,
                'is_active' => $ruleData['is_active'] ?? true,
                'notes' => $ruleData['notes'] ?? null,
            ]);
            
            $action = 'created';
        }
        
        $this->logging->business('warehouse.auto_reorder_rule.' . $action, [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'rule_id' => $rule->id,
        ]);
        
        return [
            'rule_id' => $rule->id,
            'action' => $action,
            'material_id' => $materialId,
            'warehouse_id' => $warehouseId,
            'min_stock' => (float)$rule->min_stock,
            'max_stock' => (float)$rule->max_stock,
            'reorder_point' => (float)$rule->reorder_point,
            'reorder_quantity' => (float)$rule->reorder_quantity,
            'is_active' => $rule->is_active,
        ];
    }

    /**
     * Проверить необходимость автопополнения
     */
    public function checkAutoReorder(int $organizationId): array
    {
        $rules = AutoReorderRule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with(['material', 'warehouse', 'defaultSupplier'])
            ->get();
        
        $ordersToGenerate = [];
        $rulesChecked = 0;
        
        foreach ($rules as $rule) {
            $rulesChecked++;
            
            // Получаем текущий остаток (сумма по всем партиям)
            $currentStock = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $rule->warehouse_id)
                ->where('material_id', $rule->material_id)
                ->sum('available_quantity');
            
            // Проверяем нужно ли пополнение
            if ($rule->needsReorder($currentStock)) {
                $orderQuantity = $rule->calculateOrderQuantity($currentStock);
                
                $ordersToGenerate[] = [
                    'rule_id' => $rule->id,
                    'material_id' => $rule->material_id,
                    'material_name' => $rule->material->name,
                    'material_code' => $rule->material->code,
                    'warehouse_id' => $rule->warehouse_id,
                    'warehouse_name' => $rule->warehouse->name,
                    'current_stock' => $currentStock,
                    'reorder_point' => (float)$rule->reorder_point,
                    'min_stock' => (float)$rule->min_stock,
                    'max_stock' => (float)$rule->max_stock,
                    'recommended_order_quantity' => $orderQuantity,
                    'supplier_id' => $rule->default_supplier_id,
                    'supplier_name' => $rule->defaultSupplier->name ?? null,
                    'priority' => $this->calculateOrderPriority($currentStock, $rule->reorder_point, $rule->min_stock),
                    'estimated_stock_out_days' => $this->estimateStockOutDays($organizationId, $rule->material_id, $currentStock),
                ];
                
                // Обновляем время последней проверки
                $rule->update(['last_checked_at' => now()]);
            } else {
                // Просто обновляем время проверки
                $rule->update(['last_checked_at' => now()]);
            }
        }
        
        // Сортируем по приоритету
        usort($ordersToGenerate, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        $this->logging->business('warehouse.auto_reorder.checked', [
            'organization_id' => $organizationId,
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
        ]);
        
        return [
            'checked_at' => now()->toDateTimeString(),
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
            'orders' => $ordersToGenerate,
            'summary' => [
                'critical_orders' => collect($ordersToGenerate)->where('priority', '>=', 8)->count(),
                'high_priority_orders' => collect($ordersToGenerate)->whereBetween('priority', [5, 7])->count(),
                'normal_orders' => collect($ordersToGenerate)->where('priority', '<', 5)->count(),
            ],
        ];
    }
    
    /**
     * Вспомогательный метод для расчета дисперсии
     */
    protected function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $variance = array_reduce($values, function($carry, $value) use ($mean) {
            return $carry + pow($value - $mean, 2);
        }, 0) / count($values);
        
        return sqrt($variance) / ($mean > 0 ? $mean : 1);
    }
    
    /**
     * Получить рекомендацию по ABC/XYZ категории
     */
    protected function getAbcXyzRecommendation(string $category): string
    {
        $recommendations = [
            'AX' => 'Критический товар - строгий контроль запасов',
            'AY' => 'Критический товар - повышенные страховые запасы',
            'AZ' => 'Критический товар - максимальные страховые запасы',
            'BX' => 'Важный товар - стандартный контроль',
            'BY' => 'Важный товар - средние страховые запасы',
            'BZ' => 'Важный товар - повышенные страховые запасы',
            'CX' => 'Малоценный товар - упрощенный контроль',
            'CY' => 'Малоценный товар - стандартные запасы',
            'CZ' => 'Малоценный товар - минимальный контроль',
        ];
        
        return $recommendations[$category] ?? 'Требуется анализ';
    }

    /**
     * Рассчитать приоритет заказа (1-10)
     */
    protected function calculateOrderPriority(float $currentStock, float $reorderPoint, float $minStock): int
    {
        if ($currentStock <= 0) {
            return 10;
        }
        if ($currentStock < $minStock) {
            return 9;
        }
        if ($currentStock < $reorderPoint) {
            $ratio = ($reorderPoint - $currentStock) / ($reorderPoint - $minStock);
            return max(5, min(8, (int)(5 + $ratio * 3)));
        }
        return 3;
    }
    
    /**
     * Оценить количество дней до исчерпания запасов
     */
    protected function estimateStockOutDays(int $organizationId, int $materialId, float $currentStock): ?int
    {
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('movement_type', 'write_off')
            ->where('movement_date', '>=', now()->subDays(30))
            ->get();
        
        if ($movements->isEmpty()) return null;
        
        $totalConsumption = $movements->sum('quantity');
        $averageDailyConsumption = $totalConsumption / 30;
        
        if ($averageDailyConsumption <= 0) return null;
        
        return (int)($currentStock / $averageDailyConsumption);
    }
}

