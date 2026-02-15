<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseZoneRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseZoneController extends Controller
{
    public function index(Request $request, int $warehouseId): JsonResponse
    {
        $zones = WarehouseZone::where('warehouse_id', $warehouseId)
            ->when($request->boolean('active_only'), function ($q) {
                $q->where('is_active', true);
            })
            ->when($request->input('zone_type'), function ($q, $type) {
                $q->where('zone_type', $type);
            })
            ->orderBy('name')
            ->get();
        
        return AdminResponse::success($zones);
    }

    public function store(WarehouseZoneRequest $request, int $warehouseId): JsonResponse
    {
        $validated = $request->validated();

        $exists = WarehouseZone::where('warehouse_id', $warehouseId)
            ->where('code', $validated['code'])
            ->exists();
        
        if ($exists) {
            return AdminResponse::error(trans('warehouse::messages.zone_code_exists'), 422);
        }

        $zone = WarehouseZone::create([
            'warehouse_id' => $warehouseId,
            ...$validated,
        ]);

        return AdminResponse::success($zone, trans('warehouse::messages.zone_created'), 201);
    }

    public function show(int $warehouseId, int $id): JsonResponse
    {
        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        return AdminResponse::success($zone);
    }

    public function update(WarehouseZoneRequest $request, int $warehouseId, int $id): JsonResponse
    {
        $validated = $request->validated();
        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        if (isset($validated['code']) && $validated['code'] !== $zone->code) {
            $exists = WarehouseZone::where('warehouse_id', $warehouseId)
                ->where('code', $validated['code'])
                ->where('id', '!=', $id)
                ->exists();
            
            if ($exists) {
                return AdminResponse::error(trans('warehouse::messages.zone_code_exists'), 422);
            }
        }

        $zone->update($validated);

        return AdminResponse::success($zone, trans('warehouse::messages.zone_updated'));
    }

    public function destroy(int $warehouseId, int $id): JsonResponse
    {
        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        $zone->delete();

        return AdminResponse::success(null, trans('warehouse::messages.zone_deleted'));
    }
}
