<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use Illuminate\Database\Eloquent\Builder;

class MobileWarehouseTaskService
{
    public function listTasks(int $organizationId, int $warehouseId, array $filters = []): array
    {
        $warehouse = $this->findWarehouse($organizationId, $warehouseId);

        $tasks = $this->baseTaskQuery($organizationId, $warehouse->id)
            ->when(isset($filters['status']) && $filters['status'] !== '', fn (Builder $query) => $query->where('status', (string) $filters['status']))
            ->when(isset($filters['task_type']) && $filters['task_type'] !== '', fn (Builder $query) => $query->where('task_type', (string) $filters['task_type']))
            ->when(isset($filters['priority']) && $filters['priority'] !== '', fn (Builder $query) => $query->where('priority', (string) $filters['priority']))
            ->when(isset($filters['assigned_to_id']) && $filters['assigned_to_id'] !== '', fn (Builder $query) => $query->where('assigned_to_id', (int) $filters['assigned_to_id']))
            ->when(isset($filters['zone_id']) && $filters['zone_id'] !== '', fn (Builder $query) => $query->where('zone_id', (int) $filters['zone_id']))
            ->when(isset($filters['cell_id']) && $filters['cell_id'] !== '', fn (Builder $query) => $query->where('cell_id', (int) $filters['cell_id']))
            ->when(isset($filters['logistic_unit_id']) && $filters['logistic_unit_id'] !== '', fn (Builder $query) => $query->where('logistic_unit_id', (int) $filters['logistic_unit_id']))
            ->when(isset($filters['material_id']) && $filters['material_id'] !== '', fn (Builder $query) => $query->where('material_id', (int) $filters['material_id']))
            ->when(isset($filters['project_id']) && $filters['project_id'] !== '', fn (Builder $query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(
                isset($filters['q']) && trim((string) $filters['q']) !== '',
                fn (Builder $query) => $query->where(function (Builder $nestedQuery) use ($filters): void {
                    $search = '%' . trim((string) $filters['q']) . '%';
                    $nestedQuery
                        ->where('task_number', 'like', $search)
                        ->orWhere('title', 'like', $search)
                        ->orWhere('notes', 'like', $search);
                })
            )
            ->when(
                isset($filters['entity_type'], $filters['entity_id']) &&
                trim((string) $filters['entity_type']) !== '' &&
                (string) $filters['entity_id'] !== '',
                fn (Builder $query) => $this->applyEntityFilter(
                    $query,
                    (string) $filters['entity_type'],
                    (int) $filters['entity_id']
                )
            )
            ->orderByRaw("
                CASE priority
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    ELSE 4
                END
            ")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(100, (int) ($filters['limit'] ?? 50))))
            ->get();

        return $tasks->map(fn (WarehouseTask $task) => $this->serializeTask($task))->values()->all();
    }

    public function getTask(int $organizationId, int $warehouseId, int $taskId): array
    {
        $warehouse = $this->findWarehouse($organizationId, $warehouseId);
        $task = $this->findTask($organizationId, $warehouse->id, $taskId);

        return $this->serializeTask($task);
    }

    public function updateTaskStatus(
        int $organizationId,
        int $warehouseId,
        int $taskId,
        string $status,
        ?int $userId,
        ?float $completedQuantity = null,
        ?string $notes = null
    ): array {
        $warehouse = $this->findWarehouse($organizationId, $warehouseId);
        $task = $this->findTask($organizationId, $warehouse->id, $taskId);

        $task = $this->applyStatusTransition(
            $task,
            $status,
            $userId,
            $completedQuantity,
            $notes
        );

        return $this->serializeTask($this->findTask($organizationId, $warehouse->id, $task->id));
    }

    public function findRelatedTasks(
        int $organizationId,
        ?int $warehouseId,
        string $entityType,
        int $entityId,
        int $limit = 10
    ): array {
        if ($warehouseId === null) {
            return [];
        }

        $query = $this->baseTaskQuery($organizationId, $warehouseId);
        $this->applyEntityFilter($query, $entityType, $entityId);

        $tasks = $query
            ->orderByRaw("
                CASE priority
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    ELSE 4
                END
            ")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $tasks->map(fn (WarehouseTask $task) => $this->serializeTask($task))->values()->all();
    }

    public function serializeTask(WarehouseTask $task): array
    {
        $plannedQuantity = $task->planned_quantity !== null ? (float) $task->planned_quantity : null;
        $completedQuantity = $task->completed_quantity !== null ? (float) $task->completed_quantity : null;
        $progressPercent = null;

        if ($plannedQuantity !== null && $plannedQuantity > 0 && $completedQuantity !== null) {
            $progressPercent = round(min(100, ($completedQuantity / $plannedQuantity) * 100), 1);
        }

        return [
            'id' => $task->id,
            'warehouse_id' => $task->warehouse_id,
            'zone_id' => $task->zone_id,
            'cell_id' => $task->cell_id,
            'logistic_unit_id' => $task->logistic_unit_id,
            'material_id' => $task->material_id,
            'project_id' => $task->project_id,
            'inventory_act_id' => $task->inventory_act_id,
            'movement_id' => $task->movement_id,
            'assigned_to_id' => $task->assigned_to_id,
            'task_number' => $task->task_number,
            'title' => $task->title,
            'task_type' => $task->task_type,
            'status' => $task->status,
            'priority' => $task->priority,
            'planned_quantity' => $plannedQuantity,
            'completed_quantity' => $completedQuantity,
            'progress_percent' => $progressPercent,
            'due_at' => optional($task->due_at)?->toDateTimeString(),
            'started_at' => optional($task->started_at)?->toDateTimeString(),
            'completed_at' => optional($task->completed_at)?->toDateTimeString(),
            'metadata' => $task->metadata ?? [],
            'notes' => $task->notes,
            'warehouse' => $task->warehouse ? [
                'id' => $task->warehouse->id,
                'name' => $task->warehouse->name,
                'code' => $task->warehouse->code,
                'warehouse_type' => $task->warehouse->warehouse_type,
            ] : null,
            'zone' => $task->zone ? [
                'id' => $task->zone->id,
                'name' => $task->zone->name,
                'code' => $task->zone->code,
            ] : null,
            'cell' => $task->cell ? [
                'id' => $task->cell->id,
                'name' => $task->cell->name,
                'code' => $task->cell->code,
                'full_address' => $task->cell->full_address,
            ] : null,
            'logistic_unit' => $task->logisticUnit ? [
                'id' => $task->logisticUnit->id,
                'name' => $task->logisticUnit->name,
                'code' => $task->logisticUnit->code,
                'unit_type' => $task->logisticUnit->unit_type,
                'status' => $task->logisticUnit->status,
            ] : null,
            'material' => $task->material ? [
                'id' => $task->material->id,
                'name' => $task->material->name,
                'code' => $task->material->code,
                'unit' => $task->material->measurementUnit?->code ?? $task->material->measurementUnit?->name,
            ] : null,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'status' => $task->project->status,
            ] : null,
            'inventory_act' => $task->inventoryAct ? [
                'id' => $task->inventoryAct->id,
                'act_number' => $task->inventoryAct->act_number,
                'status' => $task->inventoryAct->status,
            ] : null,
            'movement' => $task->movement ? [
                'id' => $task->movement->id,
                'movement_type' => $task->movement->movement_type,
                'document_number' => $task->movement->document_number,
            ] : null,
            'assigned_to' => $task->assignedTo ? [
                'id' => $task->assignedTo->id,
                'name' => $task->assignedTo->name,
                'email' => $task->assignedTo->email,
            ] : null,
            'creator' => $task->creator ? [
                'id' => $task->creator->id,
                'name' => $task->creator->name,
                'email' => $task->creator->email,
            ] : null,
            'completed_by' => $task->completedBy ? [
                'id' => $task->completedBy->id,
                'name' => $task->completedBy->name,
                'email' => $task->completedBy->email,
            ] : null,
            'created_at' => optional($task->created_at)?->toDateTimeString(),
            'updated_at' => optional($task->updated_at)?->toDateTimeString(),
        ];
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function findTask(int $organizationId, int $warehouseId, int $taskId): WarehouseTask
    {
        return $this->baseTaskQuery($organizationId, $warehouseId)->findOrFail($taskId);
    }

    private function baseTaskQuery(int $organizationId, int $warehouseId): Builder
    {
        return WarehouseTask::query()
            ->with([
                'warehouse:id,name,code,warehouse_type',
                'zone:id,name,code',
                'cell:id,name,code,zone_id,rack_number,shelf_number,bin_number',
                'cell.zone:id,name,code',
                'logisticUnit:id,name,code,unit_type,status,zone_id,cell_id',
                'material:id,name,code,measurement_unit_id',
                'material.measurementUnit:id,name,code',
                'project:id,name,status',
                'inventoryAct:id,act_number,status',
                'movement:id,movement_type,document_number',
                'assignedTo:id,name,email',
                'creator:id,name,email',
                'completedBy:id,name,email',
            ])
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId);
    }

    private function applyEntityFilter(Builder $query, string $entityType, int $entityId): Builder
    {
        return match ($entityType) {
            'warehouse' => $query->where('warehouse_id', $entityId),
            'zone' => $query->where('zone_id', $entityId),
            'cell' => $query->where('cell_id', $entityId),
            'logistic_unit' => $query->where('logistic_unit_id', $entityId),
            'asset' => $query->where('material_id', $entityId),
            'inventory_act' => $query->where('inventory_act_id', $entityId),
            'movement' => $query->where('movement_id', $entityId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applyStatusTransition(
        WarehouseTask $task,
        string $nextStatus,
        ?int $userId,
        ?float $completedQuantity = null,
        ?string $notes = null
    ): WarehouseTask {
        $currentStatus = $task->status;

        if (! $this->isAllowedTransition($currentStatus, $nextStatus)) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.task.status_invalid_transition'));
        }

        $updateData = ['status' => $nextStatus];

        if ($notes !== null) {
            $updateData['notes'] = $notes;
        }

        if ($nextStatus === WarehouseTask::STATUS_IN_PROGRESS && $task->started_at === null) {
            $updateData['started_at'] = now();
        }

        if ($nextStatus === WarehouseTask::STATUS_COMPLETED) {
            $updateData['started_at'] = $task->started_at ?? now();
            $updateData['completed_at'] = now();
            $updateData['completed_by_id'] = $userId;
            $updateData['completed_quantity'] = $completedQuantity
                ?? ($task->planned_quantity !== null ? (float) $task->planned_quantity : (float) ($task->completed_quantity ?? 0));
        }

        if ($completedQuantity !== null && $nextStatus !== WarehouseTask::STATUS_COMPLETED) {
            $updateData['completed_quantity'] = $completedQuantity;
        }

        $task->update($updateData);

        return $task->fresh();
    }

    private function isAllowedTransition(string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === $nextStatus) {
            return true;
        }

        $map = [
            WarehouseTask::STATUS_DRAFT => [
                WarehouseTask::STATUS_QUEUED,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_QUEUED => [
                WarehouseTask::STATUS_IN_PROGRESS,
                WarehouseTask::STATUS_BLOCKED,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_IN_PROGRESS => [
                WarehouseTask::STATUS_BLOCKED,
                WarehouseTask::STATUS_COMPLETED,
                WarehouseTask::STATUS_CANCELLED,
                WarehouseTask::STATUS_QUEUED,
            ],
            WarehouseTask::STATUS_BLOCKED => [
                WarehouseTask::STATUS_QUEUED,
                WarehouseTask::STATUS_IN_PROGRESS,
                WarehouseTask::STATUS_CANCELLED,
            ],
            WarehouseTask::STATUS_COMPLETED => [],
            WarehouseTask::STATUS_CANCELLED => [
                WarehouseTask::STATUS_QUEUED,
            ],
        ];

        return in_array($nextStatus, $map[$currentStatus] ?? [], true);
    }
}
