<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseZoneRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseStorageLoadSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseZoneController extends Controller
{
    public function __construct(
        private readonly WarehouseStorageLoadSummaryService $loadSummaryService
    ) {}

    public function index(Request $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);

            $zones = WarehouseZone::query()
                ->where('warehouse_id', $warehouse->id)
                ->when(
                    $request->boolean('active_only'),
                    fn ($query) => $query->where('is_active', true)
                )
                ->when(
                    $request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '',
                    fn ($query) => $query->where('is_active', $request->boolean('is_active'))
                )
                ->when(
                    $request->filled('zone_type'),
                    fn ($query) => $query->where('zone_type', (string) $request->input('zone_type'))
                )
                ->orderBy('zone_type')
                ->orderBy('name')
                ->get();

            $zoneSummaries = $this->loadSummaryService->summarizeZones($organizationId, $warehouse->id);

            return AdminResponse::success(
                $zones->map(
                    fn (WarehouseZone $zone) => $this->makeZonePayload(
                        $zone,
                        $zoneSummaries[$zone->id] ?? $this->emptyZoneSummary()
                    )
                )->values()->all()
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.zone.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseZoneController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only(['active_only', 'is_active', 'zone_type']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.zone.list_error'), 500);
        }
    }

    public function store(WarehouseZoneRequest $request, int $warehouseId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $validated = $request->validated();

            $exists = WarehouseZone::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('basic_warehouse.zone.code_exists'), 422);
            }

            $zone = WarehouseZone::create([
                'warehouse_id' => $warehouse->id,
                ...$validated,
            ]);

            return AdminResponse::success(
                $this->makeZonePayload($zone, $this->emptyZoneSummary()),
                trans_message('basic_warehouse.zone.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.zone.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseZoneController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'payload' => $request->only([
                    'name',
                    'code',
                    'zone_type',
                    'type',
                    'rack_number',
                    'shelf_number',
                    'cell_number',
                    'capacity',
                    'max_weight',
                    'is_active',
                ]),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.zone.create_error'), 500);
        }
    }

    public function show(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $zone = $this->findZone($warehouse->id, $id);
            $zoneSummaries = $this->loadSummaryService->summarizeZones($organizationId, $warehouse->id);

            return AdminResponse::success(
                $this->makeZonePayload($zone, $zoneSummaries[$zone->id] ?? $this->emptyZoneSummary())
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.zone.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseZoneController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'zone_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.zone.show_error'), 500);
        }
    }

    public function update(WarehouseZoneRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $validated = $request->validated();
            $zone = $this->findZone($warehouse->id, $id);

            if (isset($validated['code']) && $validated['code'] !== $zone->code) {
                $exists = WarehouseZone::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $zone->id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('basic_warehouse.zone.code_exists'), 422);
                }
            }

            $zone->update($validated);

            $zoneSummaries = $this->loadSummaryService->summarizeZones($organizationId, $warehouse->id);

            return AdminResponse::success(
                $this->makeZonePayload($zone->fresh(), $zoneSummaries[$zone->id] ?? $this->emptyZoneSummary()),
                trans_message('basic_warehouse.zone.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.zone.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseZoneController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'zone_id' => $id,
                'payload' => $request->only([
                    'name',
                    'code',
                    'zone_type',
                    'type',
                    'rack_number',
                    'shelf_number',
                    'cell_number',
                    'capacity',
                    'max_weight',
                    'is_active',
                ]),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.zone.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $zone = $this->findZone($warehouse->id, $id);

            $hasCells = WarehouseStorageCell::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('zone_id', $zone->id)
                ->exists();

            if ($hasCells) {
                return AdminResponse::error(trans_message('basic_warehouse.zone.delete_has_cells'), 422);
            }

            $hasLogisticUnits = WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where(function ($query) use ($zone): void {
                    $query->where('zone_id', $zone->id)
                        ->orWhereHas('cell', fn ($cellQuery) => $cellQuery->where('zone_id', $zone->id));
                })
                ->exists();

            if ($hasLogisticUnits) {
                return AdminResponse::error(trans_message('basic_warehouse.zone.delete_has_logistic_units'), 422);
            }

            $hasTasks = WarehouseTask::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where(function ($query) use ($zone): void {
                    $query->where('zone_id', $zone->id)
                        ->orWhereHas('cell', fn ($cellQuery) => $cellQuery->where('zone_id', $zone->id));
                })
                ->exists();

            if ($hasTasks) {
                return AdminResponse::error(trans_message('basic_warehouse.zone.delete_has_tasks'), 422);
            }

            $hasIdentifiers = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouse->id)
                ->where('entity_type', 'zone')
                ->where('entity_id', $zone->id)
                ->exists();

            if ($hasIdentifiers) {
                return AdminResponse::error(trans_message('basic_warehouse.zone.delete_has_identifiers'), 422);
            }

            $zone->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.zone.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.zone.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseZoneController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'zone_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.zone.delete_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function findZone(int $warehouseId, int $zoneId): WarehouseZone
    {
        return WarehouseZone::query()
            ->where('warehouse_id', $warehouseId)
            ->findOrFail($zoneId);
    }

    private function emptyZoneSummary(): array
    {
        return [
            'cells_count' => 0,
            'active_cells_count' => 0,
            'stored_quantity' => 0.0,
            'capacity' => null,
            'measurement_unit' => null,
            'quantity_breakdown' => [],
            'has_mixed_measurement_units' => false,
            'has_incomplete_measurement_units' => false,
            'current_utilization' => null,
        ];
    }

    private function makeZonePayload(WarehouseZone $zone, array $summary): array
    {
        $capacity = $zone->capacity !== null ? (float) $zone->capacity : null;
        $currentUtilization = $summary['current_utilization'];
        if ($currentUtilization === null && $summary['stored_quantity'] === 0.0 && $capacity !== null && $capacity > 0) {
            $currentUtilization = 0.0;
        }

        return [
            'id' => $zone->id,
            'warehouse_id' => $zone->warehouse_id,
            'name' => $zone->name,
            'code' => $zone->code,
            'zone_type' => $zone->zone_type,
            'rack_number' => $zone->rack_number,
            'shelf_number' => $zone->shelf_number,
            'cell_number' => $zone->cell_number,
            'capacity' => $capacity,
            'max_weight' => $zone->max_weight !== null ? (float) $zone->max_weight : null,
            'stored_quantity' => $summary['stored_quantity'],
            'measurement_unit' => $summary['measurement_unit'],
            'quantity_breakdown' => $summary['quantity_breakdown'],
            'has_mixed_measurement_units' => $summary['has_mixed_measurement_units'],
            'has_incomplete_measurement_units' => $summary['has_incomplete_measurement_units'],
            'current_utilization' => $currentUtilization,
            'summary' => $summary,
            'full_address' => $zone->full_address,
            'storage_conditions' => $zone->storage_conditions ?? [],
            'is_active' => (bool) $zone->is_active,
            'notes' => $zone->notes,
            'created_at' => optional($zone->created_at)?->toDateTimeString(),
            'updated_at' => optional($zone->updated_at)?->toDateTimeString(),
        ];
    }
}
