<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Контроллер для управления распределением материалов по проектам
 */
class ProjectAllocationController extends Controller
{
    /**
     * Распределить материал со склада на проект
     */
    public function allocate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'project_id' => 'required|exists:projects,id',
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
        ]);

        $organizationId = $request->user()->current_organization_id;

        DB::beginTransaction();
        try {
            // Проверяем остаток на складе
            $balance = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $validated['warehouse_id'])
                ->where('material_id', $validated['material_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Проверяем сколько уже распределено
            $totalAllocated = WarehouseProjectAllocation::where('warehouse_id', $validated['warehouse_id'])
                ->where('material_id', $validated['material_id'])
                ->sum('allocated_quantity');

            $availableForAllocation = $balance->available_quantity - $totalAllocated;

            if ($validated['quantity'] > $availableForAllocation) {
                return response()->json([
                    'success' => false,
                    'message' => "Недостаточно материала для распределения. Доступно: {$availableForAllocation}",
                ], 422);
            }

            // Создаем или обновляем распределение
            $allocation = WarehouseProjectAllocation::updateOrCreate(
                [
                    'warehouse_id' => $validated['warehouse_id'],
                    'material_id' => $validated['material_id'],
                    'project_id' => $validated['project_id'],
                ],
                [
                    'organization_id' => $organizationId,
                    'allocated_quantity' => DB::raw('allocated_quantity + ' . $validated['quantity']),
                    'allocated_by_user_id' => $request->user()->id,
                    'allocated_at' => now(),
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $allocation->fresh(['project', 'material', 'warehouse']),
                'message' => 'Материал успешно распределен на проект',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Снять распределение материала с проекта
     */
    public function deallocate(Request $request, int $allocationId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.001',
        ]);

        $organizationId = $request->user()->current_organization_id;

        DB::beginTransaction();
        try {
            $allocation = WarehouseProjectAllocation::where('organization_id', $organizationId)
                ->findOrFail($allocationId);

            if (isset($validated['quantity'])) {
                // Частичное снятие
                if ($validated['quantity'] > $allocation->allocated_quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Количество превышает распределенное',
                    ], 422);
                }

                $allocation->allocated_quantity -= $validated['quantity'];
                
                if ($allocation->allocated_quantity <= 0) {
                    $allocation->delete();
                } else {
                    $allocation->save();
                }
            } else {
                // Полное снятие
                $allocation->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Распределение успешно снято',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить распределения для проекта
     */
    public function getProjectAllocations(Request $request, int $projectId): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;

        $allocations = WarehouseProjectAllocation::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->with(['warehouse', 'material.measurementUnit', 'allocatedBy'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $allocations->map(function ($allocation) {
                return [
                    'id' => $allocation->id,
                    'warehouse_id' => $allocation->warehouse_id,
                    'warehouse_name' => $allocation->warehouse->name,
                    'material_id' => $allocation->material_id,
                    'material_name' => $allocation->material->name,
                    'material_code' => $allocation->material->code,
                    'allocated_quantity' => (float)$allocation->allocated_quantity,
                    'measurement_unit' => $allocation->material->measurementUnit->short_name ?? null,
                    'allocated_by' => $allocation->allocatedBy->name ?? null,
                    'allocated_at' => $allocation->allocated_at?->toDateTimeString(),
                    'notes' => $allocation->notes,
                ];
            }),
        ]);
    }
}

