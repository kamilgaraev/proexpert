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
        $organizationId = $request->user()->organization_id;
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('organization_warehouses', 'code')
                    ->where('organization_id', $organizationId)
            ],
            'warehouse_type' => 'nullable|in:central,project,external',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'working_hours' => 'nullable|string|max:255',
            'is_main' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'settings' => 'nullable|array',
            'storage_conditions' => 'nullable|array',
        ]);
        
        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::create([
            'organization_id' => $organizationId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'warehouse_type' => $validated['warehouse_type'] ?? 'central',
            'description' => $validated['description'] ?? null,
            'address' => $validated['address'] ?? null,
            'contact_person' => $validated['contact_person'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'working_hours' => $validated['working_hours'] ?? null,
            'is_main' => $validated['is_main'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'settings' => $validated['settings'] ?? [],
            'storage_conditions' => $validated['storage_conditions'] ?? [],
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
        $organizationId = $request->user()->organization_id;
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('organization_warehouses', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($id)
            ],
            'warehouse_type' => 'sometimes|in:central,project,external',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'working_hours' => 'nullable|string|max:255',
            'is_main' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
            'storage_conditions' => 'nullable|array',
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

