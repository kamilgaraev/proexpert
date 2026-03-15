<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseLogisticUnitRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseLogisticUnitController extends Controller
{
    public function index(Request $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);

            $units = $this->baseUnitQuery($organizationId, $warehouse->id)
                ->when($request->filled('zone_id'), fn (Builder $query) => $query->where('zone_id', (int) $request->input('zone_id')))
                ->when($request->filled('cell_id'), fn (Builder $query) => $query->where('cell_id', (int) $request->input('cell_id')))
                ->when($request->filled('unit_type'), fn (Builder $query) => $query->where('unit_type', (string) $request->input('unit_type')))
                ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->input('status')))
                ->when(
                    $request->has('is_active') && $request->input('is_active') !== '',
                    fn (Builder $query) => $query->where('is_active', $request->boolean('is_active'))
                )
                ->when(
                    $request->filled('q'),
                    fn (Builder $query) => $query->where(function (Builder $nestedQuery) use ($request): void {
                        $search = '%' . trim((string) $request->input('q')) . '%';
                        $nestedQuery->where('name', 'like', $search)->orWhere('code', 'like', $search);
                    })
                )
                ->orderByDesc('updated_at')
                ->get();

            return AdminResponse::success(
                $units->map(fn (WarehouseLogisticUnit $unit) => $this->makeUnitPayload($unit))->values()->all()
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseLogisticUnitController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only(['zone_id', 'cell_id', 'unit_type', 'status', 'is_active', 'q']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.list_error'), 500);
        }
    }

    public function store(WarehouseLogisticUnitRequest $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $validated = $request->validated();

            $this->assertWarehouseRelations($organizationId, $warehouse->id, $validated);

            $exists = WarehouseLogisticUnit::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.code_exists'), 422);
            }

            $unit = WarehouseLogisticUnit::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouse->id,
                ...$validated,
            ]);

            return AdminResponse::success(
                $this->makeUnitPayload($this->reloadUnit($organizationId, $warehouse->id, $unit->id)),
                trans_message('basic_warehouse.logistic_unit.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.warehouse_not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseLogisticUnitController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'payload' => $request->only(['name', 'code', 'unit_type', 'status', 'zone_id', 'cell_id']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.create_error'), 500);
        }
    }

    public function show(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $unit = $this->findUnit($organizationId, $warehouse->id, $id);

            return AdminResponse::success($this->makeUnitPayload($unit));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseLogisticUnitController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'unit_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.show_error'), 500);
        }
    }

    public function update(WarehouseLogisticUnitRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $unit = $this->findUnit($organizationId, $warehouse->id, $id);
            $validated = $request->validated();

            $this->assertWarehouseRelations($organizationId, $warehouse->id, $validated, $unit);

            if (isset($validated['code']) && $validated['code'] !== $unit->code) {
                $exists = WarehouseLogisticUnit::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $unit->id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.code_exists'), 422);
                }
            }

            $unit->update($validated);

            return AdminResponse::success(
                $this->makeUnitPayload($this->reloadUnit($organizationId, $warehouse->id, $unit->id)),
                trans_message('basic_warehouse.logistic_unit.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseLogisticUnitController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'unit_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $unit = $this->findUnit($organizationId, $warehouse->id, $id);

            if ($unit->childUnits()->exists()) {
                return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.delete_has_children'), 422);
            }

            $hasIdentifiers = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('entity_type', 'logistic_unit')
                ->where('entity_id', $unit->id)
                ->exists();

            if ($hasIdentifiers) {
                return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.delete_has_identifiers'), 422);
            }

            $unit->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.logistic_unit.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseLogisticUnitController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'unit_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.logistic_unit.delete_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function findUnit(int $organizationId, int $warehouseId, int $unitId): WarehouseLogisticUnit
    {
        return $this->baseUnitQuery($organizationId, $warehouseId)->findOrFail($unitId);
    }

    private function reloadUnit(int $organizationId, int $warehouseId, int $unitId): WarehouseLogisticUnit
    {
        return $this->findUnit($organizationId, $warehouseId, $unitId);
    }

    private function baseUnitQuery(int $organizationId, int $warehouseId): Builder
    {
        return WarehouseLogisticUnit::query()
            ->with([
                'zone:id,name,code',
                'cell:id,name,code,zone_id,rack_number,shelf_number,bin_number',
                'cell.zone:id,name,code',
                'parentUnit:id,name,code',
            ])
            ->withCount(['identifiers', 'scanEvents', 'childUnits'])
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId);
    }

    private function assertWarehouseRelations(
        int $organizationId,
        int $warehouseId,
        array $validated,
        ?WarehouseLogisticUnit $unit = null
    ): void {
        $zoneId = array_key_exists('zone_id', $validated) ? ($validated['zone_id'] !== null ? (int) $validated['zone_id'] : null) : $unit?->zone_id;
        $cellId = array_key_exists('cell_id', $validated) ? ($validated['cell_id'] !== null ? (int) $validated['cell_id'] : null) : $unit?->cell_id;
        $parentUnitId = array_key_exists('parent_unit_id', $validated) ? ($validated['parent_unit_id'] !== null ? (int) $validated['parent_unit_id'] : null) : $unit?->parent_unit_id;

        if ($zoneId !== null) {
            $zoneExists = WarehouseZone::query()
                ->where('warehouse_id', $warehouseId)
                ->where('id', $zoneId)
                ->exists();

            if (! $zoneExists) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.zone_invalid'));
            }
        }

        if ($cellId !== null) {
            $cell = WarehouseStorageCell::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->find($cellId);

            if (! $cell) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.cell_invalid'));
            }

            if ($zoneId !== null && $cell->zone_id !== null && $cell->zone_id !== $zoneId) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.cell_zone_mismatch'));
            }
        }

        if ($parentUnitId !== null) {
            $parentUnit = WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->find($parentUnitId);

            if (! $parentUnit) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.parent_invalid'));
            }

            if ($unit && $parentUnit->id === $unit->id) {
                throw new \InvalidArgumentException(trans_message('basic_warehouse.logistic_unit.parent_self'));
            }
        }
    }

    private function makeUnitPayload(WarehouseLogisticUnit $unit): array
    {
        $capacity = $unit->capacity !== null ? (float) $unit->capacity : null;
        $currentLoad = $unit->current_load !== null ? (float) $unit->current_load : null;
        $utilization = null;

        if ($capacity !== null && $capacity > 0 && $currentLoad !== null) {
            $utilization = round(min(100, ($currentLoad / $capacity) * 100), 1);
        }

        return [
            'id' => $unit->id,
            'organization_id' => $unit->organization_id,
            'warehouse_id' => $unit->warehouse_id,
            'zone_id' => $unit->zone_id,
            'cell_id' => $unit->cell_id,
            'parent_unit_id' => $unit->parent_unit_id,
            'name' => $unit->name,
            'code' => $unit->code,
            'unit_type' => $unit->unit_type,
            'status' => $unit->status,
            'capacity' => $capacity,
            'current_load' => $currentLoad,
            'gross_weight' => $unit->gross_weight !== null ? (float) $unit->gross_weight : null,
            'volume' => $unit->volume !== null ? (float) $unit->volume : null,
            'last_scanned_at' => optional($unit->last_scanned_at)?->toDateTimeString(),
            'storage_address' => $unit->storage_address,
            'current_utilization' => $utilization,
            'metadata' => $unit->metadata ?? [],
            'is_active' => (bool) $unit->is_active,
            'notes' => $unit->notes,
            'identifiers_count' => (int) ($unit->identifiers_count ?? 0),
            'scan_events_count' => (int) ($unit->scan_events_count ?? 0),
            'child_units_count' => (int) ($unit->child_units_count ?? 0),
            'zone' => $unit->zone ? [
                'id' => $unit->zone->id,
                'name' => $unit->zone->name,
                'code' => $unit->zone->code,
            ] : null,
            'cell' => $unit->cell ? [
                'id' => $unit->cell->id,
                'name' => $unit->cell->name,
                'code' => $unit->cell->code,
                'full_address' => $unit->cell->full_address,
            ] : null,
            'parent_unit' => $unit->parentUnit ? [
                'id' => $unit->parentUnit->id,
                'name' => $unit->parentUnit->name,
                'code' => $unit->parentUnit->code,
            ] : null,
            'created_at' => optional($unit->created_at)?->toDateTimeString(),
            'updated_at' => optional($unit->updated_at)?->toDateTimeString(),
        ];
    }
}
