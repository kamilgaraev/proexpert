<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use Illuminate\Database\Eloquent\Builder;

final class WarehouseRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'warehouse';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->projectMaterialDeliveries($organizationId, $projectId) as $delivery) {
            yield $this->projectMaterialDeliveryChunk($delivery);
        }

        foreach ($this->warehouseMovements($organizationId, $projectId) as $movement) {
            yield $this->warehouseMovementChunk($movement);
        }

        foreach ($this->warehouseProjectAllocations($organizationId, $projectId) as $allocation) {
            yield $this->warehouseProjectAllocationChunk($allocation);
        }

        foreach ($this->assetReservations($organizationId, $projectId) as $reservation) {
            yield $this->assetReservationChunk($reservation);
        }

        foreach ($this->warehouseTasks($organizationId, $projectId) as $task) {
            yield $this->warehouseTaskChunk($task);
        }

        foreach ($this->warehouseBalances($organizationId, $projectId) as $balance) {
            yield $this->warehouseBalanceChunk($balance, $projectId);
        }

        foreach ($this->warehouseAssets($organizationId, $projectId) as $asset) {
            yield $this->warehouseAssetChunk($asset, $projectId);
        }

        if ($projectId !== null) {
            return;
        }

        foreach ($this->inventoryActs($organizationId) as $act) {
            yield $this->inventoryActChunk($act);
        }

        foreach ($this->warehouseStorageCells($organizationId) as $cell) {
            yield $this->warehouseStorageCellChunk($cell);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'project_material_delivery', 'warehouse' => $this->singleProjectMaterialDelivery($organizationId, $entityId),
            'warehouse_balance' => $this->singleWarehouseBalance($organizationId, $entityId),
            'warehouse_movement' => $this->singleWarehouseMovement($organizationId, $entityId),
            'warehouse_project_allocation' => $this->singleWarehouseProjectAllocation($organizationId, $entityId),
            'asset_reservation' => $this->singleAssetReservation($organizationId, $entityId),
            'inventory_act' => $this->singleInventoryAct($organizationId, $entityId),
            'warehouse_storage_cell' => $this->singleWarehouseStorageCell($organizationId, $entityId),
            'warehouse_task' => $this->singleWarehouseTask($organizationId, $entityId),
            'warehouse_asset', 'asset' => $this->singleWarehouseAsset($organizationId, $entityId),
            default => [],
        };
    }

    private function projectMaterialDeliveries(int $organizationId, ?int $projectId): iterable
    {
        return ProjectMaterialDelivery::query()
            ->with(['project', 'material', 'warehouse', 'siteRequest', 'purchaseRequest'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseMovements(int $organizationId, ?int $projectId): iterable
    {
        return WarehouseMovement::query()
            ->with(['warehouse', 'fromWarehouse', 'toWarehouse', 'material', 'project', 'user'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseProjectAllocations(int $organizationId, ?int $projectId): iterable
    {
        return WarehouseProjectAllocation::query()
            ->with(['warehouse', 'material', 'project', 'allocatedBy'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function assetReservations(int $organizationId, ?int $projectId): iterable
    {
        return AssetReservation::query()
            ->with(['warehouse', 'material', 'project', 'reservedBy'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseTasks(int $organizationId, ?int $projectId): iterable
    {
        return WarehouseTask::query()
            ->with([
                'warehouse',
                'zone',
                'cell',
                'logisticUnit',
                'material',
                'project',
                'inventoryAct',
                'movement',
                'assignedTo',
                'creator',
                'completedBy',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseBalances(int $organizationId, ?int $projectId): iterable
    {
        return WarehouseBalance::query()
            ->with(['warehouse', 'material'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, function (Builder $query) use ($projectId): void {
                $this->whereAllocationMatchesProject($query, $projectId, 'warehouse_balances');
            })
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseAssets(int $organizationId, ?int $projectId): iterable
    {
        return Asset::query()
            ->with(['warehouseBalances.warehouse'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($projectId): void {
                if ($projectId === null) {
                    $query->whereExists(function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('warehouse_balances')
                            ->whereColumn('warehouse_balances.material_id', 'materials.id')
                            ->whereColumn('warehouse_balances.organization_id', 'materials.organization_id');
                    });

                    return;
                }

                $query->whereExists(function ($subQuery) use ($projectId): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from('warehouse_project_allocations')
                        ->whereColumn('warehouse_project_allocations.material_id', 'materials.id')
                        ->whereColumn('warehouse_project_allocations.organization_id', 'materials.organization_id')
                        ->where('warehouse_project_allocations.project_id', $projectId);
                });
            })
            ->orderBy('id')
            ->cursor();
    }

    private function inventoryActs(int $organizationId): iterable
    {
        return InventoryAct::query()
            ->with(['warehouse', 'creator', 'approver', 'items.material'])
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->cursor();
    }

    private function warehouseStorageCells(int $organizationId): iterable
    {
        return WarehouseStorageCell::query()
            ->with(['warehouse', 'zone'])
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->cursor();
    }

    private function singleProjectMaterialDelivery(int $organizationId, string|int $entityId): array
    {
        $delivery = ProjectMaterialDelivery::query()
            ->with(['project', 'material', 'warehouse', 'siteRequest', 'purchaseRequest'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $delivery instanceof ProjectMaterialDelivery ? [$this->projectMaterialDeliveryChunk($delivery)] : [];
    }

    private function singleWarehouseBalance(int $organizationId, string|int $entityId): array
    {
        $balance = WarehouseBalance::query()
            ->with(['warehouse', 'material'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $balance instanceof WarehouseBalance ? [$this->warehouseBalanceChunk($balance)] : [];
    }

    private function singleWarehouseMovement(int $organizationId, string|int $entityId): array
    {
        $movement = WarehouseMovement::query()
            ->with(['warehouse', 'fromWarehouse', 'toWarehouse', 'material', 'project', 'user'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $movement instanceof WarehouseMovement ? [$this->warehouseMovementChunk($movement)] : [];
    }

    private function singleWarehouseProjectAllocation(int $organizationId, string|int $entityId): array
    {
        $allocation = WarehouseProjectAllocation::query()
            ->with(['warehouse', 'material', 'project', 'allocatedBy'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $allocation instanceof WarehouseProjectAllocation ? [$this->warehouseProjectAllocationChunk($allocation)] : [];
    }

    private function singleAssetReservation(int $organizationId, string|int $entityId): array
    {
        $reservation = AssetReservation::query()
            ->with(['warehouse', 'material', 'project', 'reservedBy'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $reservation instanceof AssetReservation ? [$this->assetReservationChunk($reservation)] : [];
    }

    private function singleInventoryAct(int $organizationId, string|int $entityId): array
    {
        $act = InventoryAct::query()
            ->with(['warehouse', 'creator', 'approver', 'items.material'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $act instanceof InventoryAct ? [$this->inventoryActChunk($act)] : [];
    }

    private function singleWarehouseStorageCell(int $organizationId, string|int $entityId): array
    {
        $cell = WarehouseStorageCell::query()
            ->with(['warehouse', 'zone'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $cell instanceof WarehouseStorageCell ? [$this->warehouseStorageCellChunk($cell)] : [];
    }

    private function singleWarehouseTask(int $organizationId, string|int $entityId): array
    {
        $task = WarehouseTask::query()
            ->with([
                'warehouse',
                'zone',
                'cell',
                'logisticUnit',
                'material',
                'project',
                'inventoryAct',
                'movement',
                'assignedTo',
                'creator',
                'completedBy',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $task instanceof WarehouseTask ? [$this->warehouseTaskChunk($task)] : [];
    }

    private function singleWarehouseAsset(int $organizationId, string|int $entityId): array
    {
        $asset = Asset::query()
            ->with(['warehouseBalances.warehouse'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $asset instanceof Asset ? [$this->warehouseAssetChunk($asset)] : [];
    }

    private function projectMaterialDeliveryChunk(ProjectMaterialDelivery $delivery): RagChunkData
    {
        $content = $this->lines([
            'Поставка на проект: '.$this->stringValue($delivery->material?->name),
            'Проект: '.$this->stringValue($delivery->project?->name),
            'Склад: '.$this->stringValue($delivery->warehouse?->name),
            'Статус: '.$this->stringValue($delivery->status),
            'Запрошено: '.$this->numberValue($delivery->requested_quantity),
            'Зарезервировано: '.$this->numberValue($delivery->reserved_quantity),
            'Отгружено: '.$this->numberValue($delivery->shipped_quantity),
            'Принято: '.$this->numberValue($delivery->accepted_quantity),
            'Плановая доставка: '.$this->dateValue($delivery->planned_delivery_date),
            'Заявка с объекта: '.$this->stringValue($delivery->siteRequest?->title),
            'Закупка: '.$this->stringValue($delivery->purchaseRequest?->request_number),
            'Примечания: '.$this->stringValue($delivery->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $delivery->organization_id,
            projectId: (int) $delivery->project_id,
            sourceType: $this->sourceType(),
            entityType: 'project_material_delivery',
            entityId: (int) $delivery->id,
            title: 'Склад: '.$this->stringValue($delivery->material?->name),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($delivery->status),
                'material_id' => $delivery->material_id,
                'warehouse_id' => $delivery->warehouse_id,
                'site_request_id' => $delivery->site_request_id,
                'purchase_request_id' => $delivery->purchase_request_id,
            ],
            updatedAt: $delivery->updated_at
        );
    }

    private function warehouseBalanceChunk(WarehouseBalance $balance, ?int $projectContextId = null): RagChunkData
    {
        $content = $this->lines([
            'Остаток на складе: '.$this->stringValue($balance->material?->name),
            'Склад: '.$this->stringValue($balance->warehouse?->name),
            'Доступно: '.$this->numberValue($balance->available_quantity),
            'Зарезервировано: '.$this->numberValue($balance->reserved_quantity),
            'Минимальный остаток: '.$this->numberValue($balance->min_stock_level),
            'Максимальный остаток: '.$this->numberValue($balance->max_stock_level),
            'Цена: '.$this->moneyValue($balance->unit_price),
            'Ячейка: '.$this->stringValue($balance->location_code),
            'Партия: '.$this->stringValue($balance->batch_number),
            'Серийный номер: '.$this->stringValue($balance->serial_number),
            'Срок годности: '.$this->dateValue($balance->expiry_date),
            'Последнее движение: '.$this->dateTimeValue($balance->last_movement_at),
        ]);

        return new RagChunkData(
            organizationId: (int) $balance->organization_id,
            projectId: $projectContextId,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_balance',
            entityId: (int) $balance->id,
            title: 'Остаток склада: '.$this->stringValue($balance->material?->name),
            content: $content,
            metadata: [
                'warehouse_id' => $balance->warehouse_id,
                'material_id' => $balance->material_id,
                'project_id' => $projectContextId,
                'available_quantity' => $balance->available_quantity,
                'reserved_quantity' => $balance->reserved_quantity,
                'is_low_stock' => $balance->isLowStock(),
                'needs_reorder' => $balance->needsReorder(),
            ],
            updatedAt: $balance->last_movement_at ?? $balance->created_at
        );
    }

    private function warehouseMovementChunk(WarehouseMovement $movement): RagChunkData
    {
        $projectId = $movement->project_id !== null ? (int) $movement->project_id : null;
        $content = $this->lines([
            'Движение склада: '.$this->stringValue($movement->movement_type),
            'Материал: '.$this->stringValue($movement->material?->name),
            'Склад: '.$this->stringValue($movement->warehouse?->name),
            'Со склада: '.$this->stringValue($movement->fromWarehouse?->name),
            'На склад: '.$this->stringValue($movement->toWarehouse?->name),
            'Проект: '.$this->stringValue($movement->project?->name),
            'Количество: '.$this->numberValue($movement->quantity),
            'Цена: '.$this->moneyValue($movement->price),
            'Документ: '.$this->stringValue($movement->document_number),
            'Причина: '.$this->stringValue($movement->reason),
            'Дата движения: '.$this->dateTimeValue($movement->movement_date),
            'Пользователь: '.$this->stringValue($movement->user?->name),
        ]);

        return new RagChunkData(
            organizationId: (int) $movement->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_movement',
            entityId: (int) $movement->id,
            title: 'Движение склада: '.$this->stringValue($movement->document_number ?: $movement->movement_type),
            content: $content,
            metadata: [
                'movement_type' => $movement->movement_type,
                'project_id' => $projectId,
                'warehouse_id' => $movement->warehouse_id,
                'from_warehouse_id' => $movement->from_warehouse_id,
                'to_warehouse_id' => $movement->to_warehouse_id,
                'material_id' => $movement->material_id,
            ],
            updatedAt: $movement->updated_at
        );
    }

    private function warehouseProjectAllocationChunk(WarehouseProjectAllocation $allocation): RagChunkData
    {
        $projectId = (int) $allocation->project_id;
        $content = $this->lines([
            'Распределение склада на проект: '.$this->stringValue($allocation->material?->name),
            'Проект: '.$this->stringValue($allocation->project?->name),
            'Склад: '.$this->stringValue($allocation->warehouse?->name),
            'Количество: '.$this->numberValue($allocation->allocated_quantity),
            'Выделил: '.$this->stringValue($allocation->allocatedBy?->name),
            'Дата выделения: '.$this->dateTimeValue($allocation->allocated_at),
            'Примечания: '.$this->stringValue($allocation->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $allocation->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_project_allocation',
            entityId: (int) $allocation->id,
            title: 'Распределение склада: '.$this->stringValue($allocation->material?->name),
            content: $content,
            metadata: [
                'project_id' => $projectId,
                'warehouse_id' => $allocation->warehouse_id,
                'material_id' => $allocation->material_id,
                'allocated_quantity' => $allocation->allocated_quantity,
            ],
            updatedAt: $allocation->updated_at
        );
    }

    private function assetReservationChunk(AssetReservation $reservation): RagChunkData
    {
        $projectId = $reservation->project_id !== null ? (int) $reservation->project_id : null;
        $content = $this->lines([
            'Резерв склада: '.$this->stringValue($reservation->material?->name),
            'Проект: '.$this->stringValue($reservation->project?->name),
            'Склад: '.$this->stringValue($reservation->warehouse?->name),
            'Статус: '.$this->stringValue($reservation->status),
            'Количество: '.$this->numberValue($reservation->quantity),
            'Зарезервировал: '.$this->stringValue($reservation->reservedBy?->name),
            'Дата резерва: '.$this->dateTimeValue($reservation->reserved_at),
            'Истекает: '.$this->dateTimeValue($reservation->expires_at),
            'Исполнено: '.$this->dateTimeValue($reservation->fulfilled_at),
            'Отменено: '.$this->dateTimeValue($reservation->cancelled_at),
            'Причина: '.$this->stringValue($reservation->reason),
        ]);

        return new RagChunkData(
            organizationId: (int) $reservation->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'asset_reservation',
            entityId: (int) $reservation->id,
            title: 'Резерв склада: '.$this->stringValue($reservation->material?->name),
            content: $content,
            metadata: [
                'status' => $reservation->status,
                'project_id' => $projectId,
                'warehouse_id' => $reservation->warehouse_id,
                'material_id' => $reservation->material_id,
                'quantity' => $reservation->quantity,
            ],
            updatedAt: $reservation->updated_at
        );
    }

    private function inventoryActChunk(InventoryAct $act): RagChunkData
    {
        $items = $act->items
            ->take(5)
            ->map(fn ($item): string => trim(sprintf(
                '%s план %s факт %s разница %s',
                $this->stringValue($item->material?->name),
                $this->numberValue($item->expected_quantity),
                $this->numberValue($item->actual_quantity),
                $this->numberValue($item->difference)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Акт инвентаризации: '.$this->stringValue($act->act_number),
            'Склад: '.$this->stringValue($act->warehouse?->name),
            'Статус: '.$this->stringValue($act->status),
            'Дата инвентаризации: '.$this->dateValue($act->inventory_date),
            'Начато: '.$this->dateTimeValue($act->started_at),
            'Завершено: '.$this->dateTimeValue($act->completed_at),
            'Утверждено: '.$this->dateTimeValue($act->approved_at),
            'Создал: '.$this->stringValue($act->creator?->name),
            'Утвердил: '.$this->stringValue($act->approver?->name),
            'Комиссия: '.$this->arrayValue($act->commission_members),
            'Итоги: '.$this->arrayValue($act->summary),
            'Позиции: '.implode(', ', $items),
            'Примечания: '.$this->stringValue($act->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $act->organization_id,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'inventory_act',
            entityId: (int) $act->id,
            title: 'Инвентаризация склада: '.$this->stringValue($act->act_number),
            content: $content,
            metadata: [
                'status' => $act->status,
                'warehouse_id' => $act->warehouse_id,
                'items_count' => $act->items->count(),
                'inventory_date' => $this->dateValue($act->inventory_date),
            ],
            updatedAt: $act->updated_at
        );
    }

    private function warehouseStorageCellChunk(WarehouseStorageCell $cell): RagChunkData
    {
        $content = $this->lines([
            'Ячейка склада: '.$this->stringValue($cell->name),
            'Код: '.$this->stringValue($cell->code),
            'Адрес: '.$this->stringValue($cell->full_address),
            'Склад: '.$this->stringValue($cell->warehouse?->name),
            'Зона: '.$this->stringValue($cell->zone?->name),
            'Тип: '.$this->stringValue($cell->cell_type),
            'Статус: '.$this->stringValue($cell->status),
            'Стеллаж: '.$this->stringValue($cell->rack_number),
            'Полка: '.$this->stringValue($cell->shelf_number),
            'Место: '.$this->stringValue($cell->bin_number),
            'Вместимость: '.$this->numberValue($cell->capacity),
            'Максимальный вес: '.$this->numberValue($cell->max_weight),
            'Активна: '.$this->boolValue($cell->is_active),
            'Примечания: '.$this->stringValue($cell->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $cell->organization_id,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_storage_cell',
            entityId: (int) $cell->id,
            title: 'Ячейка склада: '.$this->stringValue($cell->code ?: $cell->name),
            content: $content,
            metadata: [
                'warehouse_id' => $cell->warehouse_id,
                'zone_id' => $cell->zone_id,
                'cell_type' => $cell->cell_type,
                'status' => $cell->status,
                'is_active' => (bool) $cell->is_active,
            ],
            updatedAt: $cell->updated_at
        );
    }

    private function warehouseTaskChunk(WarehouseTask $task): RagChunkData
    {
        $projectId = $task->project_id !== null ? (int) $task->project_id : null;
        $content = $this->lines([
            'Задача склада: '.$this->stringValue($task->title),
            'Номер: '.$this->stringValue($task->task_number),
            'Тип: '.$this->stringValue($task->task_type),
            'Статус: '.$this->stringValue($task->status),
            'Приоритет: '.$this->stringValue($task->priority),
            'Проект: '.$this->stringValue($task->project?->name),
            'Склад: '.$this->stringValue($task->warehouse?->name),
            'Зона: '.$this->stringValue($task->zone?->name),
            'Ячейка: '.$this->stringValue($task->cell?->full_address),
            'Грузовая единица: '.$this->stringValue($task->logisticUnit?->code),
            'Материал: '.$this->stringValue($task->material?->name),
            'План: '.$this->numberValue($task->planned_quantity),
            'Факт: '.$this->numberValue($task->completed_quantity),
            'Срок: '.$this->dateTimeValue($task->due_at),
            'Начато: '.$this->dateTimeValue($task->started_at),
            'Завершено: '.$this->dateTimeValue($task->completed_at),
            'Исполнитель: '.$this->stringValue($task->assignedTo?->name),
            'Создал: '.$this->stringValue($task->creator?->name),
            'Завершил: '.$this->stringValue($task->completedBy?->name),
            'Акт инвентаризации: '.$this->stringValue($task->inventoryAct?->act_number),
            'Движение: '.$this->stringValue($task->movement?->document_number),
            'Источник: '.$this->stringValue($task->source_document_type).' #'.$this->stringValue($task->source_document_id),
            'Примечания: '.$this->stringValue($task->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $task->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_task',
            entityId: (int) $task->id,
            title: 'Задача склада: '.$this->stringValue($task->task_number ?: $task->title),
            content: $content,
            metadata: [
                'status' => $task->status,
                'priority' => $task->priority,
                'task_type' => $task->task_type,
                'project_id' => $projectId,
                'warehouse_id' => $task->warehouse_id,
                'material_id' => $task->material_id,
            ],
            updatedAt: $task->updated_at
        );
    }

    private function warehouseAssetChunk(Asset $asset, ?int $projectContextId = null): RagChunkData
    {
        $balances = $asset->warehouseBalances
            ->take(5)
            ->map(fn (WarehouseBalance $balance): string => trim(sprintf(
                '%s: %s доступно, %s резерв',
                $this->stringValue($balance->warehouse?->name),
                $this->numberValue($balance->available_quantity),
                $this->numberValue($balance->reserved_quantity)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Складской актив: '.$this->stringValue($asset->name),
            'Код: '.$this->stringValue($asset->code),
            'Тип: '.$this->stringValue($asset->asset_type),
            'Категория: '.$this->stringValue($asset->asset_category ?? $asset->category),
            'Подкатегория: '.$this->stringValue($asset->asset_subcategory),
            'Единица измерения: '.$this->stringValue($asset->measurementUnit?->short_name),
            'Цена по умолчанию: '.$this->moneyValue($asset->default_price),
            'Внешний код: '.$this->stringValue($asset->external_code),
            'Складские остатки: '.implode(', ', $balances),
            'Описание: '.$this->stringValue($asset->description),
        ]);

        return new RagChunkData(
            organizationId: (int) $asset->organization_id,
            projectId: $projectContextId,
            sourceType: $this->sourceType(),
            entityType: 'warehouse_asset',
            entityId: (int) $asset->id,
            title: 'Складской актив: '.$this->stringValue($asset->name),
            content: $content,
            metadata: [
                'project_id' => $projectContextId,
                'material_id' => $asset->id,
                'asset_type' => $asset->asset_type,
                'asset_category' => $asset->asset_category ?? $asset->category,
                'is_active' => (bool) $asset->is_active,
                'balances_count' => $asset->warehouseBalances->count(),
            ],
            updatedAt: $asset->updated_at
        );
    }

    private function whereAllocationMatchesProject(Builder $query, int $projectId, string $outerTable): void
    {
        $query->whereExists(function ($subQuery) use ($projectId, $outerTable): void {
            $subQuery
                ->selectRaw('1')
                ->from('warehouse_project_allocations')
                ->whereColumn('warehouse_project_allocations.organization_id', $outerTable.'.organization_id')
                ->whereColumn('warehouse_project_allocations.warehouse_id', $outerTable.'.warehouse_id')
                ->whereColumn('warehouse_project_allocations.material_id', $outerTable.'.material_id')
                ->where('warehouse_project_allocations.project_id', $projectId);
        });
    }
}
