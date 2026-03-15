<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseTaskRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseTaskStatusRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseTaskController extends Controller
{
    public function index(Request $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);

            $tasks = $this->baseTaskQuery($organizationId, $warehouse->id)
                ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->input('status')))
                ->when($request->filled('task_type'), fn (Builder $query) => $query->where('task_type', (string) $request->input('task_type')))
                ->when($request->filled('priority'), fn (Builder $query) => $query->where('priority', (string) $request->input('priority')))
                ->when($request->filled('assigned_to_id'), fn (Builder $query) => $query->where('assigned_to_id', (int) $request->input('assigned_to_id')))
                ->when($request->filled('zone_id'), fn (Builder $query) => $query->where('zone_id', (int) $request->input('zone_id')))
                ->when($request->filled('cell_id'), fn (Builder $query) => $query->where('cell_id', (int) $request->input('cell_id')))
                ->when($request->filled('logistic_unit_id'), fn (Builder $query) => $query->where('logistic_unit_id', (int) $request->input('logistic_unit_id')))
                ->when($request->filled('material_id'), fn (Builder $query) => $query->where('material_id', (int) $request->input('material_id')))
                ->when($request->filled('project_id'), fn (Builder $query) => $query->where('project_id', (int) $request->input('project_id')))
                ->when(
                    $request->filled('q'),
                    fn (Builder $query) => $query->where(function (Builder $nestedQuery) use ($request): void {
                        $search = '%' . trim((string) $request->input('q')) . '%';
                        $nestedQuery
                            ->where('task_number', 'like', $search)
                            ->orWhere('title', 'like', $search)
                            ->orWhere('notes', 'like', $search);
                    })
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
                ->get();

            return AdminResponse::success(
                $tasks->map(fn (WarehouseTask $task) => $this->makeTaskPayload($task))->values()->all()
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only([
                    'status',
                    'task_type',
                    'priority',
                    'assigned_to_id',
                    'zone_id',
                    'cell_id',
                    'logistic_unit_id',
                    'material_id',
                    'project_id',
                    'q',
                ]),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.list_error'), 500);
        }
    }

    public function store(WarehouseTaskRequest $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $validated = $request->validated();

            $this->assertWarehouseRelations($organizationId, $warehouse->id, $validated);

            $task = WarehouseTask::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouse->id,
                'created_by_id' => $request->user()?->id,
                'task_number' => $this->generateTaskNumber($warehouse->id),
                'status' => $validated['status'] ?? WarehouseTask::STATUS_QUEUED,
                'priority' => $validated['priority'] ?? WarehouseTask::PRIORITY_NORMAL,
                'completed_quantity' => $validated['completed_quantity'] ?? null,
                ...$validated,
            ]);

            if ($task->status !== WarehouseTask::STATUS_DRAFT) {
                $task = $this->applyStatusTransition(
                    $task,
                    $task->status,
                    $request->user()?->id,
                    isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null,
                    $validated['notes'] ?? null,
                    false
                );
            }

            return AdminResponse::success(
                $this->makeTaskPayload($this->reloadTask($organizationId, $warehouse->id, $task->id)),
                trans_message('basic_warehouse.task.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.warehouse_not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'payload' => $request->only(['title', 'task_type', 'priority', 'assigned_to_id', 'zone_id', 'cell_id']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.create_error'), 500);
        }
    }

    public function show(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $task = $this->findTask($organizationId, $warehouse->id, $id);

            return AdminResponse::success($this->makeTaskPayload($task));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'task_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.show_error'), 500);
        }
    }

    public function update(WarehouseTaskRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $task = $this->findTask($organizationId, $warehouse->id, $id);
            $validated = $request->validated();
            $requestedStatus = isset($validated['status']) ? (string) $validated['status'] : null;

            if ($requestedStatus !== null) {
                unset($validated['status']);
            }

            $this->assertWarehouseRelations($organizationId, $warehouse->id, $validated, $task);

            $task->update($validated);

            if ($requestedStatus !== null) {
                $task = $this->applyStatusTransition(
                    $task->fresh(),
                    $requestedStatus,
                    $request->user()?->id,
                    isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null,
                    $validated['notes'] ?? null
                );
            }

            return AdminResponse::success(
                $this->makeTaskPayload($this->reloadTask($organizationId, $warehouse->id, $task->id)),
                trans_message('basic_warehouse.task.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'task_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.update_error'), 500);
        }
    }

    public function updateStatus(WarehouseTaskStatusRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $task = $this->findTask($organizationId, $warehouse->id, $id);
            $validated = $request->validated();

            $task = $this->applyStatusTransition(
                $task,
                (string) $validated['status'],
                $request->user()?->id,
                isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null,
                $validated['notes'] ?? null
            );

            return AdminResponse::success(
                $this->makeTaskPayload($this->reloadTask($organizationId, $warehouse->id, $task->id)),
                trans_message('basic_warehouse.task.status_updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::updateStatus error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'task_id' => $id,
                'payload' => $request->only(['status', 'completed_quantity']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $task = $this->findTask($organizationId, $warehouse->id, $id);

            if (! in_array($task->status, [WarehouseTask::STATUS_DRAFT, WarehouseTask::STATUS_QUEUED, WarehouseTask::STATUS_CANCELLED], true)) {
                return AdminResponse::error(trans_message('basic_warehouse.task.delete_restricted'), 422);
            }

            $task->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.task.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseTaskController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'task_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.task.delete_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
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

    private function findTask(int $organizationId, int $warehouseId, int $taskId): WarehouseTask
    {
        return $this->baseTaskQuery($organizationId, $warehouseId)->findOrFail($taskId);
    }

    private function reloadTask(int $organizationId, int $warehouseId, int $taskId): WarehouseTask
    {
        return $this->findTask($organizationId, $warehouseId, $taskId);
    }

    private function assertWarehouseRelations(
        int $organizationId,
        int $warehouseId,
        array $validated,
        ?WarehouseTask $task = null
    ): void {
        $zoneId = $this->resolveRelationId($validated, 'zone_id', $task?->zone_id);
        $cellId = $this->resolveRelationId($validated, 'cell_id', $task?->cell_id);
        $logisticUnitId = $this->resolveRelationId($validated, 'logistic_unit_id', $task?->logistic_unit_id);
        $materialId = $this->resolveRelationId($validated, 'material_id', $task?->material_id);
        $projectId = $this->resolveRelationId($validated, 'project_id', $task?->project_id);
        $inventoryActId = $this->resolveRelationId($validated, 'inventory_act_id', $task?->inventory_act_id);
        $movementId = $this->resolveRelationId($validated, 'movement_id', $task?->movement_id);
        $assignedToId = $this->resolveRelationId($validated, 'assigned_to_id', $task?->assigned_to_id);

        if ($zoneId !== null) {
            $zoneExists = WarehouseZone::query()
                ->where('warehouse_id', $warehouseId)
                ->where('id', $zoneId)
                ->exists();

            if (! $zoneExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.zone_invalid'));
            }
        }

        if ($cellId !== null) {
            $cell = WarehouseStorageCell::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->find($cellId);

            if (! $cell) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.cell_invalid'));
            }

            if ($zoneId !== null && $cell->zone_id !== null && $cell->zone_id !== $zoneId) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.cell_zone_mismatch'));
            }
        }

        if ($logisticUnitId !== null) {
            $unit = WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->find($logisticUnitId);

            if (! $unit) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.logistic_unit_invalid'));
            }

            if ($cellId !== null && $unit->cell_id !== null && $unit->cell_id !== $cellId) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.logistic_unit_cell_mismatch'));
            }

            if ($zoneId !== null && $unit->zone_id !== null && $unit->zone_id !== $zoneId) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.logistic_unit_zone_mismatch'));
            }
        }

        if ($materialId !== null) {
            $materialExists = Material::query()
                ->where('organization_id', $organizationId)
                ->where('id', $materialId)
                ->exists();

            if (! $materialExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.material_invalid'));
            }
        }

        if ($projectId !== null) {
            $projectExists = Project::query()
                ->where('organization_id', $organizationId)
                ->where('id', $projectId)
                ->exists();

            if (! $projectExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.project_invalid'));
            }
        }

        if ($inventoryActId !== null) {
            $inventoryActExists = InventoryAct::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('id', $inventoryActId)
                ->exists();

            if (! $inventoryActExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.inventory_act_invalid'));
            }
        }

        if ($movementId !== null) {
            $movementExists = WarehouseMovement::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('id', $movementId)
                ->exists();

            if (! $movementExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.movement_invalid'));
            }
        }

        if ($assignedToId !== null) {
            $assignedUserExists = User::query()
                ->where('current_organization_id', $organizationId)
                ->where('id', $assignedToId)
                ->exists();

            if (! $assignedUserExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.task.assigned_user_invalid'));
            }
        }
    }

    private function resolveRelationId(array $validated, string $key, ?int $fallback = null): ?int
    {
        if (! array_key_exists($key, $validated)) {
            return $fallback;
        }

        return $validated[$key] !== null ? (int) $validated[$key] : null;
    }

    private function applyStatusTransition(
        WarehouseTask $task,
        string $nextStatus,
        ?int $userId,
        ?float $completedQuantity = null,
        ?string $notes = null,
        bool $validateTransition = true
    ): WarehouseTask {
        $currentStatus = $task->status;

        if ($validateTransition && ! $this->isAllowedTransition($currentStatus, $nextStatus)) {
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

    private function generateTaskNumber(int $warehouseId): string
    {
        return sprintf('WT-%d-%s', $warehouseId, now()->format('ymdHis'));
    }

    private function makeTaskPayload(WarehouseTask $task): array
    {
        $plannedQuantity = $task->planned_quantity !== null ? (float) $task->planned_quantity : null;
        $completedQuantity = $task->completed_quantity !== null ? (float) $task->completed_quantity : null;
        $progressPercent = null;

        if ($plannedQuantity !== null && $plannedQuantity > 0 && $completedQuantity !== null) {
            $progressPercent = round(min(100, ($completedQuantity / $plannedQuantity) * 100), 1);
        }

        return [
            'id' => $task->id,
            'organization_id' => $task->organization_id,
            'warehouse_id' => $task->warehouse_id,
            'zone_id' => $task->zone_id,
            'cell_id' => $task->cell_id,
            'logistic_unit_id' => $task->logistic_unit_id,
            'material_id' => $task->material_id,
            'project_id' => $task->project_id,
            'inventory_act_id' => $task->inventory_act_id,
            'movement_id' => $task->movement_id,
            'assigned_to_id' => $task->assigned_to_id,
            'created_by_id' => $task->created_by_id,
            'completed_by_id' => $task->completed_by_id,
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
            'source_document_type' => $task->source_document_type,
            'source_document_id' => $task->source_document_id !== null ? (int) $task->source_document_id : null,
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
}
