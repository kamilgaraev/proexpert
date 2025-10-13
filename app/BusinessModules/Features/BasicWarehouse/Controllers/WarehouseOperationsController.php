<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для операций со складом (приход, списание, перемещение)
 */
class WarehouseOperationsController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {}

    /**
     * Оприходовать активы на склад
     */
    public function receipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'required|numeric|min:0',
            'project_id' => 'nullable|exists:projects,id',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $result = $this->warehouseService->receiveAsset(
            $organizationId,
            $validated['warehouse_id'],
            $validated['material_id'],
            $validated['quantity'],
            $validated['price'],
            [
                'project_id' => $validated['project_id'] ?? null,
                'user_id' => $request->user()->id,
                'document_number' => $validated['document_number'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'metadata' => $validated['metadata'] ?? [],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Активы успешно оприходованы',
        ], 201);
    }

    /**
     * Списать активы со склада
     */
    public function writeOff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => 'nullable|exists:projects,id',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'required|string',
            'metadata' => 'nullable|array',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $result = $this->warehouseService->writeOffAsset(
            $organizationId,
            $validated['warehouse_id'],
            $validated['material_id'],
            $validated['quantity'],
            [
                'project_id' => $validated['project_id'] ?? null,
                'user_id' => $request->user()->id,
                'document_number' => $validated['document_number'] ?? null,
                'reason' => $validated['reason'],
                'metadata' => $validated['metadata'] ?? [],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Активы успешно списаны',
        ]);
    }

    /**
     * Переместить активы между складами
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:organization_warehouses,id',
            'to_warehouse_id' => 'required|exists:organization_warehouses,id|different:from_warehouse_id',
            'material_id' => 'required|exists:materials,id',
            'quantity' => 'required|numeric|min:0.001',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $organizationId = $request->user()->organization_id;
        
        $result = $this->warehouseService->transferAsset(
            $organizationId,
            $validated['from_warehouse_id'],
            $validated['to_warehouse_id'],
            $validated['material_id'],
            $validated['quantity'],
            [
                'user_id' => $request->user()->id,
                'document_number' => $validated['document_number'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'metadata' => $validated['metadata'] ?? [],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Активы успешно перемещены',
        ]);
    }
}

