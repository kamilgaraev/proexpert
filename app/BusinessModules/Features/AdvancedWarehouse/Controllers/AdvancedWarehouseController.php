<?php

namespace App\BusinessModules\Features\AdvancedWarehouse\Controllers;

use App\BusinessModules\Features\AdvancedWarehouse\Services\AdvancedWarehouseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для продвинутых функций склада
 */
class AdvancedWarehouseController extends Controller
{
    public function __construct(
        protected AdvancedWarehouseService $advancedWarehouseService
    ) {}

    /**
     * Получить аналитику оборачиваемости
     */
    public function turnoverAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:organization_warehouses,id',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $analytics = $this->advancedWarehouseService->getTurnoverAnalytics($organizationId, $validated);
        
        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    /**
     * Получить прогноз потребности
     */
    public function forecast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon_days' => 'nullable|integer|min:7|max:365',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'exists:materials,id',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $forecast = $this->advancedWarehouseService->getForecastData($organizationId, $validated);
        
        return response()->json([
            'success' => true,
            'data' => $forecast,
        ]);
    }

    /**
     * Получить ABC/XYZ анализ
     */
    public function abcXyzAnalysis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $analysis = $this->advancedWarehouseService->getAbcXyzAnalysis($organizationId, $validated);
        
        return response()->json([
            'success' => true,
            'data' => $analysis,
        ]);
    }

    /**
     * Зарезервировать активы
     */
    public function reserve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => 'nullable|exists:projects,id',
            'expires_hours' => 'nullable|integer|min:1|max:168',
            'reason' => 'nullable|string',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $result = $this->advancedWarehouseService->reserveAssets(
            $organizationId,
            $validated['warehouse_id'],
            $validated['material_id'],
            $validated['quantity'],
            [
                'project_id' => $validated['project_id'] ?? null,
                'user_id' => $request->user()->id,
                'expires_hours' => $validated['expires_hours'] ?? 24,
                'reason' => $validated['reason'] ?? null,
            ]
        );
        
        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Активы зарезервированы',
        ], 201);
    }

    /**
     * Снять резервирование
     */
    public function unreserve(Request $request, int $reservationId): JsonResponse
    {
        $result = $this->advancedWarehouseService->unreserveAssets($reservationId);
        
        return response()->json([
            'success' => true,
            'message' => 'Резервирование снято',
        ]);
    }

    /**
     * Получить список резерваций
     */
    public function reservations(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $reservations = \App\BusinessModules\Features\AdvancedWarehouse\Models\AssetReservation::where('organization_id', $organizationId)
            ->with(['material', 'warehouse', 'project', 'reservedBy'])
            ->when($request->input('status'), function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->input('warehouse_id'), function ($q, $warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $reservations,
        ]);
    }

    /**
     * Создать правило автопополнения
     */
    public function createAutoReorderRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'min_stock' => 'required|numeric|min:0',
            'max_stock' => 'required|numeric|gt:min_stock',
            'reorder_point' => 'required|numeric|gte:min_stock|lte:max_stock',
            'reorder_quantity' => 'required|numeric|min:0.001',
            'default_supplier_id' => 'nullable|exists:suppliers,id',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $result = $this->advancedWarehouseService->createAutoReorderRule(
            $organizationId,
            $validated['material_id'],
            $validated
        );
        
        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Правило автопополнения ' . ($result['action'] === 'created' ? 'создано' : 'обновлено'),
        ], $result['action'] === 'created' ? 201 : 200);
    }

    /**
     * Получить список правил автопополнения
     */
    public function autoReorderRules(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $rules = \App\BusinessModules\Features\AdvancedWarehouse\Models\AutoReorderRule::where('organization_id', $organizationId)
            ->with(['material', 'warehouse', 'defaultSupplier'])
            ->when($request->input('warehouse_id'), function ($q, $warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->when($request->boolean('active_only'), function ($q) {
                $q->where('is_active', true);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Проверить необходимость автопополнения
     */
    public function checkAutoReorder(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $result = $this->advancedWarehouseService->checkAutoReorder($organizationId);
        
        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}

