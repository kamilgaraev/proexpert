<?php

namespace App\BusinessModules\Features\AdvancedWarehouse\Controllers;

use App\BusinessModules\Features\AdvancedWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для управления зонами хранения
 */
class WarehouseZoneController extends Controller
{
    /**
     * Получить список зон склада
     */
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
        
        return response()->json([
            'success' => true,
            'data' => $zones,
        ]);
    }

    /**
     * Создать зону хранения
     */
    public function store(Request $request, int $warehouseId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'zone_type' => 'required|in:storage,receiving,shipping,quarantine,returns',
            'rack_number' => 'nullable|string|max:50',
            'shelf_number' => 'nullable|string|max:50',
            'cell_number' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'storage_conditions' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        // Проверяем уникальность кода в пределах склада
        $exists = WarehouseZone::where('warehouse_id', $warehouseId)
            ->where('code', $validated['code'])
            ->exists();
        
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Зона с таким кодом уже существует на этом складе',
            ], 422);
        }

        $zone = WarehouseZone::create([
            'warehouse_id' => $warehouseId,
            ...$validated,
        ]);

        return response()->json([
            'success' => true,
            'data' => $zone,
            'message' => 'Зона хранения создана',
        ], 201);
    }

    /**
     * Получить информацию о зоне
     */
    public function show(int $warehouseId, int $id): JsonResponse
    {
        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $zone,
        ]);
    }

    /**
     * Обновить зону хранения
     */
    public function update(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50',
            'zone_type' => 'sometimes|in:storage,receiving,shipping,quarantine,returns',
            'rack_number' => 'nullable|string|max:50',
            'shelf_number' => 'nullable|string|max:50',
            'cell_number' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'storage_conditions' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        // Проверяем уникальность кода если он изменился
        if (isset($validated['code']) && $validated['code'] !== $zone->code) {
            $exists = WarehouseZone::where('warehouse_id', $warehouseId)
                ->where('code', $validated['code'])
                ->where('id', '!=', $id)
                ->exists();
            
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Зона с таким кодом уже существует на этом складе',
                ], 422);
            }
        }

        $zone->update($validated);

        return response()->json([
            'success' => true,
            'data' => $zone,
            'message' => 'Зона хранения обновлена',
        ]);
    }

    /**
     * Удалить зону хранения
     */
    public function destroy(int $warehouseId, int $id): JsonResponse
    {
        $zone = WarehouseZone::where('warehouse_id', $warehouseId)
            ->findOrFail($id);
        
        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Зона хранения удалена',
        ]);
    }
}

