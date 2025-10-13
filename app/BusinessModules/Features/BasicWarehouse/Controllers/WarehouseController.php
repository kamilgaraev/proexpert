<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для управления складами
 */
class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {}

    /**
     * Получить список складов организации
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $warehouses = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $warehouses,
        ]);
    }

    /**
     * Создать новый склад
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:500',
            'type' => 'required|in:main,branch,mobile,virtual',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_person_id' => 'nullable|exists:users,id',
            'settings' => 'nullable|array',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::create([
            'organization_id' => $organizationId,
            ...$validated,
        ]);

        return response()->json([
            'success' => true,
            'data' => $warehouse,
            'message' => 'Склад успешно создан',
        ], 201);
    }

    /**
     * Получить информацию о складе
     */
    public function show(int $id): JsonResponse
    {
        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::with(['balances.material'])
            ->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $warehouse,
        ]);
    }

    /**
     * Обновить склад
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'nullable|string|max:500',
            'type' => 'sometimes|in:main,branch,mobile,virtual',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_person_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ]);

        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::findOrFail($id);
        $warehouse->update($validated);

        return response()->json([
            'success' => true,
            'data' => $warehouse,
            'message' => 'Склад успешно обновлен',
        ]);
    }

    /**
     * Удалить склад (мягкое удаление)
     */
    public function destroy(int $id): JsonResponse
    {
        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::findOrFail($id);
        $warehouse->delete();

        return response()->json([
            'success' => true,
            'message' => 'Склад успешно удален',
        ]);
    }

    /**
     * Получить остатки на складе
     */
    public function balances(Request $request, int $id): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $filters = [
            'warehouse_id' => $id,
            'asset_type' => $request->input('asset_type'),
            'low_stock' => $request->boolean('low_stock'),
        ];
        
        $balances = $this->warehouseService->getStockData($organizationId, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $balances,
        ]);
    }

    /**
     * Получить движения по складу
     */
    public function movements(Request $request, int $id): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $filters = [
            'warehouse_id' => $id,
            'movement_type' => $request->input('movement_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
        
        $movements = $this->warehouseService->getMovementsData($organizationId, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }
}

