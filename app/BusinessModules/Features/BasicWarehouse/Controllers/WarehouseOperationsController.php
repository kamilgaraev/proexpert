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
     * УМНЫЙ ПРИХОД: можно указать material_id ИЛИ создать новый материал на лету
     */
    public function receipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            
            // Либо существующий материал
            'material_id' => 'nullable|exists:materials,id',
            
            // Либо данные для создания нового материала
            'material' => 'nullable|array',
            'material.name' => 'required_without:material_id|string|max:255',
            'material.code' => 'nullable|string|max:50',
            'material.measurement_unit_id' => 'required_without:material_id|exists:measurement_units,id',
            'material.category' => 'nullable|string|max:100',
            'material.asset_type' => 'nullable|string|in:material,equipment,tool,consumable',
            'material.default_price' => 'nullable|numeric|min:0',
            'material.description' => 'nullable|string',
            
            // Данные прихода
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'required|numeric|min:0',
            'project_id' => 'nullable|exists:projects,id',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $organizationId = $request->user()->current_organization_id;
        
        // Определяем material_id
        $materialId = $validated['material_id'] ?? null;
        
        // Если material_id не указан, создаем новый материал
        if (!$materialId && isset($validated['material'])) {
            $material = \App\Models\Material::create([
                'organization_id' => $organizationId,
                'name' => $validated['material']['name'],
                'code' => $validated['material']['code'] ?? null,
                'measurement_unit_id' => $validated['material']['measurement_unit_id'],
                'category' => $validated['material']['category'] ?? null,
                'default_price' => $validated['material']['default_price'] ?? $validated['price'],
                'description' => $validated['material']['description'] ?? null,
                'additional_properties' => [
                    'asset_type' => $validated['material']['asset_type'] ?? 'material',
                ],
                'is_active' => true,
            ]);
            
            $materialId = $material->id;
        }
        
        if (!$materialId) {
            return response()->json([
                'success' => false,
                'message' => 'Необходимо указать material_id или данные для создания нового материала (material)',
            ], 422);
        }
        
        $result = $this->warehouseService->receiveAsset(
            $organizationId,
            $validated['warehouse_id'],
            $materialId,
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

        $organizationId = $request->user()->current_organization_id;
        
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

        $organizationId = $request->user()->current_organization_id;
        
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

