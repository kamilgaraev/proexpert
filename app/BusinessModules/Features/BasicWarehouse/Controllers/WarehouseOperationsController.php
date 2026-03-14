<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReceiptRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReserveRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TransferRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TransferToContractorRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UnreserveRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WriteOffRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePhotoService;
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
        protected WarehouseService $warehouseService,
        protected AssetService $assetService,
        protected WarehousePhotoService $warehousePhotoService,
        protected \App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseExportManager $exportManager
    ) {}

    /**
     * Экспорт Приходного ордера (М-4)
     */
    public function exportM4(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            $dataToExport = $movement;
            if ($movement->document_number) {
                $dataToExport = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('document_number', $movement->document_number)
                    ->where('organization_id', $movement->organization_id)
                    ->where('movement_type', $movement->movement_type)
                    ->get();
            }

            $path = $this->exportManager->export('m4', $dataToExport);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-4: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт Требования-накладной (М-11)
     */
    public function exportM11(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            $dataToExport = $movement;
            if ($movement->document_number) {
                $dataToExport = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('document_number', $movement->document_number)
                    ->where('organization_id', $movement->organization_id)
                    ->where('movement_type', $movement->movement_type)
                    ->get();
            }

            $path = $this->exportManager->export('m11', $dataToExport);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-11: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт Накладной на отпуск на сторону (М-15)
     */
    public function exportM15(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            $dataToExport = $movement;
            if ($movement->document_number) {
                $dataToExport = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('document_number', $movement->document_number)
                    ->where('organization_id', $movement->organization_id)
                    ->where('movement_type', $movement->movement_type)
                    ->get();
            }

            $path = $this->exportManager->export('m15', $dataToExport);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-15: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт Акта о приемке (М-7)
     */
    public function exportM7(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            $dataToExport = $movement;
            if ($movement->document_number) {
                $dataToExport = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('document_number', $movement->document_number)
                    ->where('organization_id', $movement->organization_id)
                    ->where('movement_type', $movement->movement_type)
                    ->get();
            }

            $path = $this->exportManager->export('m7', $dataToExport);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-7: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт Карточки учета материалов (М-17)
     */
    public function exportM17(int $materialId, Request $request): JsonResponse
    {
        $material = \App\Models\Material::findOrFail($materialId);
        $warehouseId = (int) $request->query('warehouse_id');

        if ($material->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            // Получаем движения материала на конкретном складе
            $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('material_id', $materialId)
                ->where('warehouse_id', $warehouseId)
                ->orderBy('movement_date', 'asc')
                ->get();

            $path = $this->exportManager->export('m17', [
                'material' => $material,
                'warehouse_id' => $warehouseId,
                'movements' => $movements
            ]);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-17: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт Лимитно-заборной карты (М-8)
     */
    public function exportM8(int $reservationId, Request $request): JsonResponse
    {
        $reservation = \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::findOrFail($reservationId);
        
        if ($reservation->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error('Доступ запрещен', 403);
        }

        try {
            // Получаем движения, связанные с этим резервом (списания)
            $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('material_id', $reservation->material_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('movement_type', 'write-off')
                ->where('movement_date', '>=', $reservation->created_at)
                ->get();

            $path = $this->exportManager->export('m8', [
                'reservation' => $reservation,
                'movements' => $movements
            ]);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], __('warehouse_basic.export_success'));
        } catch (\Exception $e) {
            return AdminResponse::error('Ошибка экспорта М-8: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Оприходовать активы на склад
     * УМНЫЙ ПРИХОД: можно указать material_id ИЛИ создать новый материал на лету
     */
    public function receipt(ReceiptRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $request->user()->current_organization_id;
        
        $materialId = $validated['material_id'] ?? null;

        if (!$materialId && isset($validated['material'])) {
            $materialData = $validated['material'];
            $asset = $this->assetService->createAsset($organizationId, [
                'name'                => $materialData['name'],
                'code'                => $materialData['code'] ?? null,
                'measurement_unit_id' => $materialData['measurement_unit_id'],
                'category'            => $materialData['category'] ?? null,
                'default_price'       => $materialData['default_price'] ?? $validated['price'],
                'description'         => $materialData['description'] ?? null,
                'asset_type'          => $materialData['asset_type'] ?? 'material',
                'is_active'           => true,
            ]);

            $materialId = $asset->id;
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

            if ($request->hasFile('photos')) {
                $this->warehousePhotoService->uploadMovementPhotos(
                    $organizationId,
                    (int) $result['movement']->id,
                    $request->file('photos', []),
                    $request->user()
                );

                $result['movement']->load('photos');
            }

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

