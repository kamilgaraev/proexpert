<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReceiptRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReserveRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TransferRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TransferToContractorRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UnreserveRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WriteOffRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Http\Resources\WarehouseMovementResource;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
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
    public function receipt(ReceiptRequest $request): JsonResponse
    {
        $validated = $request->validated();
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
            return AdminResponse::error('Необходимо указать material_id или данные для создания нового материала (material)', 422);
        }
        
        try {
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

            return AdminResponse::success(
                new WarehouseMovementResource($result['movement']), 
                __('warehouse_basic.receipt_success'), 
                201
            );
        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.receipt_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Списать активы со склада
     */
    public function writeOff(WriteOffRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        try {
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

            return AdminResponse::success([
                'movements' => WarehouseMovementResource::collection($result['movements']),
                'total_quantity' => $result['total_quantity'],
                'avg_price' => $result['avg_price'],
            ], __('warehouse_basic.write_off_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.write_off_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Переместить активы между складами
     */
    public function transfer(TransferRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        try {
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

            return AdminResponse::success([
                'movement_out' => new WarehouseMovementResource($result['movement_out']),
                'movement_in' => new WarehouseMovementResource($result['movement_in']),
                'avg_price' => $result['avg_price'],
            ], __('warehouse_basic.transfer_success'));
            
        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.transfer_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Зарезервировать активы (Жесткий резерв)
     */
    public function reserve(ReserveRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        try {
            $this->warehouseService->reserveAssets(
                $organizationId,
                $validated['warehouse_id'],
                $validated['material_id'],
                $validated['quantity'],
                [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'reason' => $validated['reason'] ?? 'Резервирование по запросу',
                    'metadata' => $validated['metadata'] ?? [],
                ]
            );

            return AdminResponse::success(null, __('warehouse_basic.reserve_success'));
            
        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.reserve_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Снять резерв
     */
    public function unreserve(UnreserveRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        try {
            $this->warehouseService->unreserveAssets(
                $organizationId,
                $validated['warehouse_id'],
                $validated['material_id'],
                $validated['quantity'],
                [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'reason' => $validated['reason'] ?? 'Снятие резерва по запросу',
                    'metadata' => $validated['metadata'] ?? [],
                ]
            );

            return AdminResponse::success(null, __('warehouse_basic.unreserve_success'));
            
        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.unreserve_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Передать материалы подрядчику (на его склад)
     */
    public function transferToContractor(
        TransferToContractorRequest $request,
        \App\BusinessModules\Features\BasicWarehouse\Services\ContractorTransferService $contractorTransferService
    ): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        try {
            $result = $contractorTransferService->transferToContractor(
                $organizationId,
                $validated['from_warehouse_id'],
                $validated['contractor_id'],
                $validated['material_id'],
                $validated['quantity'],
                $validated['project_id'] ?? null,
                $validated['document_number'] ?? null,
                $validated['reason'] ?? null,
                $request->user()->id
            );

            return AdminResponse::success($result, __('warehouse_basic.transfer_to_contractor_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(__('warehouse_basic.transfer_to_contractor_error') . ': ' . $e->getMessage(), 500);
        }
    }
}

