<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseStorageCellRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseStorageCellController extends Controller
{
    public function index(Request $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);

            $cells = WarehouseStorageCell::query()
                ->with('zone:id,name,code')
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->when($request->filled('zone_id'), fn ($query) => $query->where('zone_id', (int) $request->input('zone_id')))
                ->when($request->filled('cell_type'), fn ($query) => $query->where('cell_type', (string) $request->input('cell_type')))
                ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->input('status')))
                ->when(
                    $request->has('is_active') && $request->input('is_active') !== '',
                    fn ($query) => $query->where('is_active', $request->boolean('is_active'))
                )
                ->orderBy('name')
                ->get();

            $storedQuantities = $this->getStoredQuantitiesByCellId($warehouse->id);

            return AdminResponse::success(
                $cells->map(
                    fn (WarehouseStorageCell $cell) => $this->makeCellPayload(
                        $cell,
                        (float) ($storedQuantities[$cell->id] ?? 0)
                    )
                )->values()->all()
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.cell.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseStorageCellController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only(['zone_id', 'cell_type', 'status', 'is_active']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.cell.list_error'), 500);
        }
    }

    public function store(WarehouseStorageCellRequest $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $validated = $request->validated();

            $this->assertZoneBelongsToWarehouse($warehouse->id, $validated['zone_id'] ?? null);

            $exists = WarehouseStorageCell::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.code_exists'), 422);
            }

            $cell = WarehouseStorageCell::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouse->id,
                ...$validated,
            ]);

            return AdminResponse::success(
                $this->makeCellPayload($cell->load('zone:id,name,code'), 0),
                trans_message('basic_warehouse.cell.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.cell.warehouse_not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseStorageCellController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'payload' => $request->only(['name', 'code', 'zone_id', 'cell_type', 'status']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.cell.create_error'), 500);
        }
    }

    public function show(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $cell = $this->findCell($organizationId, $warehouse->id, $id);
            $storedQuantities = $this->getStoredQuantitiesByCellId($warehouse->id);

            return AdminResponse::success(
                $this->makeCellPayload($cell, (float) ($storedQuantities[$cell->id] ?? 0))
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.cell.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseStorageCellController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'cell_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.cell.show_error'), 500);
        }
    }

    public function update(WarehouseStorageCellRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $cell = $this->findCell($organizationId, $warehouse->id, $id);
            $validated = $request->validated();

            $this->assertZoneBelongsToWarehouse($warehouse->id, $validated['zone_id'] ?? $cell->zone_id);

            if (isset($validated['code']) && $validated['code'] !== $cell->code) {
                $exists = WarehouseStorageCell::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $cell->id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('basic_warehouse.cell.code_exists'), 422);
                }
            }

            $cell->update($validated);
            $storedQuantities = $this->getStoredQuantitiesByCellId($warehouse->id);

            return AdminResponse::success(
                $this->makeCellPayload($cell->fresh()->load('zone:id,name,code'), (float) ($storedQuantities[$cell->id] ?? 0)),
                trans_message('basic_warehouse.cell.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.cell.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseStorageCellController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'cell_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.cell.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $cell = $this->findCell($organizationId, $warehouse->id, $id);

            $hasBalances = WarehouseBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where(function ($query) use ($cell): void {
                    $query->where('cell_id', $cell->id)
                        ->orWhere(function ($legacyQuery) use ($cell): void {
                            $legacyQuery->whereNull('cell_id')
                                ->where('location_code', $cell->code);
                        });
                })
                ->exists();

            if ($hasBalances) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_balances'), 422);
            }

            $hasMovements = WarehouseMovement::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('cell_id', $cell->id)
                ->exists();

            if ($hasMovements) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_movements'), 422);
            }

            $hasInventoryActItems = InventoryActItem::query()
                ->where(function ($query) use ($cell): void {
                    $query->where('cell_id', $cell->id)
                        ->orWhere(function ($legacyQuery) use ($cell): void {
                            $legacyQuery->whereNull('cell_id')
                                ->where('location_code', $cell->code);
                        });
                })
                ->whereHas('inventoryAct', function ($query) use ($organizationId, $warehouse): void {
                    $query->where('organization_id', $organizationId)
                        ->where('warehouse_id', $warehouse->id);
                })
                ->exists();

            if ($hasInventoryActItems) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_inventory_items'), 422);
            }

            $hasLogisticUnits = WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('cell_id', $cell->id)
                ->exists();

            if ($hasLogisticUnits) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_logistic_units'), 422);
            }

            $hasUnfinishedTasks = WarehouseTask::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('cell_id', $cell->id)
                ->whereNotIn('status', [
                    WarehouseTask::STATUS_COMPLETED,
                    WarehouseTask::STATUS_CANCELLED,
                ])
                ->exists();

            if ($hasUnfinishedTasks) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_unfinished_tasks'), 422);
            }

            $hasIdentifiers = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('entity_type', 'cell')
                ->where('entity_id', $cell->id)
                ->exists();

            if ($hasIdentifiers) {
                return AdminResponse::error(trans_message('basic_warehouse.cell.delete_has_identifiers'), 422);
            }

            $cell->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.cell.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.cell.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseStorageCellController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'cell_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.cell.delete_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function findCell(int $organizationId, int $warehouseId, int $cellId): WarehouseStorageCell
    {
        return WarehouseStorageCell::query()
            ->with('zone:id,name,code')
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->findOrFail($cellId);
    }

    private function assertZoneBelongsToWarehouse(int $warehouseId, ?int $zoneId): void
    {
        if ($zoneId === null) {
            return;
        }

        $exists = WarehouseZone::query()
            ->where('warehouse_id', $warehouseId)
            ->where('id', $zoneId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.cell.zone_invalid'));
        }
    }

    private function getStoredQuantitiesByCellId(int $warehouseId): array
    {
        $cellCodes = WarehouseStorageCell::query()
            ->where('warehouse_id', $warehouseId)
            ->pluck('code', 'id');

        $quantities = WarehouseBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('cell_id')
            ->selectRaw('cell_id, SUM(available_quantity + reserved_quantity) as stored_quantity')
            ->groupBy('cell_id')
            ->pluck('stored_quantity', 'cell_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        $legacyQuantities = WarehouseBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereNull('cell_id')
            ->whereIn('location_code', $cellCodes->filter()->values())
            ->selectRaw('location_code, SUM(available_quantity + reserved_quantity) as stored_quantity')
            ->groupBy('location_code')
            ->pluck('stored_quantity', 'location_code')
            ->map(fn ($value) => (float) $value)
            ->all();

        foreach ($cellCodes as $cellId => $code) {
            $quantities[$cellId] = ($quantities[$cellId] ?? 0.0) + ($legacyQuantities[$code] ?? 0.0);
        }

        return $quantities;
    }

    private function makeCellPayload(WarehouseStorageCell $cell, float $storedQuantity): array
    {
        $capacity = $cell->capacity !== null ? (float) $cell->capacity : null;
        $currentUtilization = null;

        if ($capacity !== null && $capacity > 0) {
            $currentUtilization = round(min(100, ($storedQuantity / $capacity) * 100), 1);
        }

        return [
            'id' => $cell->id,
            'organization_id' => $cell->organization_id,
            'warehouse_id' => $cell->warehouse_id,
            'zone_id' => $cell->zone_id,
            'name' => $cell->name,
            'code' => $cell->code,
            'cell_type' => $cell->cell_type,
            'status' => $cell->status,
            'rack_number' => $cell->rack_number,
            'shelf_number' => $cell->shelf_number,
            'bin_number' => $cell->bin_number,
            'capacity' => $capacity,
            'max_weight' => $cell->max_weight !== null ? (float) $cell->max_weight : null,
            'stored_quantity' => round($storedQuantity, 3),
            'current_utilization' => $currentUtilization,
            'full_address' => $cell->full_address,
            'storage_conditions' => $cell->storage_conditions ?? [],
            'metadata' => $cell->metadata ?? [],
            'is_active' => (bool) $cell->is_active,
            'notes' => $cell->notes,
            'zone' => $cell->zone ? [
                'id' => $cell->zone->id,
                'name' => $cell->zone->name,
                'code' => $cell->zone->code,
            ] : null,
            'created_at' => optional($cell->created_at)?->toDateTimeString(),
            'updated_at' => optional($cell->updated_at)?->toDateTimeString(),
        ];
    }
}
