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
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseStorageCellResolver;
use App\BusinessModules\Features\BasicWarehouse\Http\Resources\WarehouseMovementResource;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Services\Project\ProjectService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;
use function trans_message;

/**
 * Контроллер для операций со складом (приход, списание, перемещение)
 */
class WarehouseOperationsController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService,
        protected AssetService $assetService,
        protected WarehousePhotoService $warehousePhotoService,
        protected WarehouseStorageCellResolver $storageCellResolver,
        protected ProjectService $projectService,
        protected \App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseExportManager $exportManager
    ) {}

    public function writeOffProjects(Request $request): JsonResponse
    {
        try {
            $projects = $this->projectService
                ->getActiveProjectsForCurrentOrg($request)
                ->map(static fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                ])
                ->values();

            return AdminResponse::success([
                'projects' => $projects,
            ]);
        } catch (Throwable $exception) {
            return $this->warehouseError(
                'write_off_projects',
                $exception,
                $request,
                'warehouse_basic.write_off_projects_load_error'
            );
        }
    }

    /**
     * Экспорт Приходного ордера (М-4)
     */
    public function exportM4(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
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
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m4_export', $exception, $request, 'warehouse_basic.m4_export_error', 500, [
                'movement_id' => $id,
            ]);
        }
    }

    /**
     * Экспорт Требования-накладной (М-11)
     */
    public function exportM11(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
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
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m11_export', $exception, $request, 'warehouse_basic.m11_export_error', 500, [
                'movement_id' => $id,
            ]);
        }
    }

    /**
     * Экспорт Накладной на отпуск на сторону (М-15)
     */
    public function exportM15(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
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
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m15_export', $exception, $request, 'warehouse_basic.m15_export_error', 500, [
                'movement_id' => $id,
            ]);
        }
    }

    /**
     * Экспорт Акта о приемке (М-7)
     */
    public function exportM7(int $id, Request $request): JsonResponse
    {
        $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::findOrFail($id);
        
        if ($movement->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
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
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m7_export', $exception, $request, 'warehouse_basic.m7_export_error', 500, [
                'movement_id' => $id,
            ]);
        }
    }

    /**
     * Экспорт Карточки учета материалов (М-17)
     */
    public function exportM17(int $materialId, Request $request): JsonResponse
    {
        $warehouseId = (int) $request->query('warehouse_id');
        $organizationId = (int) $request->user()->current_organization_id;
        $material = \App\Models\Material::query()
            ->with(['organization', 'measurementUnit'])
            ->where('organization_id', $organizationId)
            ->findOrFail($materialId);
        $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);

        if ($material->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
        }

        try {
            $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('material_id', $materialId)
                ->where('organization_id', $organizationId)
                ->where(static function ($query) use ($warehouseId): void {
                    $query->where('warehouse_id', $warehouseId)
                        ->orWhere('from_warehouse_id', $warehouseId)
                        ->orWhere('to_warehouse_id', $warehouseId);
                })
                ->orderBy('movement_date', 'asc')
                ->get();

            $path = $this->exportManager->export('m17', [
                'material' => $material,
                'warehouse' => $warehouse,
                'warehouse_id' => $warehouseId,
                'movements' => $movements
            ]);
            $url = $this->exportManager->getTemporaryUrl($path);
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m17_export', $exception, $request, 'warehouse_basic.m17_export_error', 500, [
                'material_id' => $materialId,
                'warehouse_id' => $warehouseId,
            ]);
        }
    }

    /**
     * Экспорт Лимитно-заборной карты (М-8)
     */
    public function exportM8(int $reservationId, Request $request): JsonResponse
    {
        $reservation = \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::findOrFail($reservationId);
        
        if ($reservation->organization_id !== $request->user()->current_organization_id) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
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
            
            return AdminResponse::success(['url' => $url], trans_message('warehouse_basic.export_success'));
        } catch (Throwable $exception) {
            return $this->warehouseError('m8_export', $exception, $request, 'warehouse_basic.m8_export_error', 500, [
                'reservation_id' => $reservationId,
            ]);
        }
    }

    /**
     * Оприходовать активы на склад
     * УМНЫЙ ПРИХОД: можно указать material_id ИЛИ создать новый материал на лету
     */
    public function receipt(ReceiptRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = (int) $request->user()->current_organization_id;
        
        $materialId = $validated['material_id'] ?? null;

        if (!$materialId && isset($validated['material'])) {
            $materialData = $validated['material'];
            $asset = $this->assetService->createAsset($organizationId, [
                'name'                => $materialData['name'],
                'code'                => $materialData['code'] ?? null,
                'measurement_unit_id' => (int) $materialData['measurement_unit_id'],
                'category'            => $materialData['category'] ?? null,
                'default_price'       => (float) ($materialData['default_price'] ?? $validated['price']),
                'description'         => $materialData['description'] ?? null,
                'asset_type'          => $materialData['asset_type'] ?? 'material',
                'is_active'           => true,
            ]);

            $materialId = $asset->id;
        }
        
        if (!$materialId) {
            return AdminResponse::error(trans_message('warehouse_basic.material_required'), 422);
        }
        
        $warehouseId = (int) $validated['warehouse_id'];
        $materialId = (int) $materialId;
        $quantity = (float) $validated['quantity'];
        $price = (float) $validated['price'];

        try {
            $cell = $this->storageCellResolver->resolveForWarehouse(
                $organizationId,
                $warehouseId,
                isset($validated['cell_id']) ? (int) $validated['cell_id'] : null
            );

            $result = $this->warehouseService->receiveAsset(
                $organizationId,
                $warehouseId,
                $materialId,
                $quantity,
                $price,
                array_merge(
                    [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => (int) $request->user()->id,
                    'document_number' => $validated['document_number'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'metadata' => $validated['metadata'] ?? [],
                    ],
                    $this->storageCellResolver->metadata($cell)
                )
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
                trans_message('warehouse_basic.receipt_success'),
                201
            );
        } catch (Throwable $exception) {
            return $this->warehouseError('receipt', $exception, $request, 'warehouse_basic.receipt_error');
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
            $cell = $this->storageCellResolver->resolveForWarehouse(
                (int) $organizationId,
                (int) $validated['warehouse_id'],
                isset($validated['cell_id']) ? (int) $validated['cell_id'] : null
            );

            $result = $this->warehouseService->writeOffAsset(
                $organizationId,
                $validated['warehouse_id'],
                $validated['material_id'],
                $validated['quantity'],
                array_merge(
                    [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'document_number' => $validated['document_number'] ?? null,
                    'reason' => $validated['reason'],
                    'operation_category' => $validated['operation_category'],
                    'metadata' => $validated['metadata'] ?? [],
                    ],
                    $this->storageCellResolver->metadata($cell)
                )
            );

            return AdminResponse::success([
                'movement' => new WarehouseMovementResource($result['movement']),
                'write_off_details' => $result['write_off_details'],
                'remaining_total_quantity' => $result['remaining_total_quantity'],
            ], trans_message('warehouse_basic.write_off_success'));

        } catch (InvalidArgumentException $exception) {
            return $this->warehouseError('write_off_validation', $exception, $request, 'warehouse_basic.operation_validation_error', 422);
        } catch (Throwable $exception) {
            return $this->warehouseError('write_off', $exception, $request, 'warehouse_basic.write_off_error');
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
            $sourceCell = $this->storageCellResolver->resolveForWarehouse(
                (int) $organizationId,
                (int) $validated['from_warehouse_id'],
                isset($validated['from_cell_id']) ? (int) $validated['from_cell_id'] : null
            );
            $targetCell = $this->storageCellResolver->resolveForWarehouse(
                (int) $organizationId,
                (int) $validated['to_warehouse_id'],
                isset($validated['to_cell_id']) ? (int) $validated['to_cell_id'] : null
            );

            $result = $this->warehouseService->transferAsset(
                $organizationId,
                $validated['from_warehouse_id'],
                $validated['to_warehouse_id'],
                $validated['material_id'],
                $validated['quantity'],
                array_merge(
                    [
                    'user_id' => $request->user()->id,
                    'document_number' => $validated['document_number'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'metadata' => $validated['metadata'] ?? [],
                    ],
                    $this->storageCellResolver->metadata($targetCell),
                    $sourceCell === null ? [] : [
                        'from_cell_id' => $sourceCell->id,
                        'from_location_code' => $sourceCell->code,
                        'from_storage_address' => $sourceCell->full_address,
                    ]
                )
            );

            return AdminResponse::success([
                'movement_out' => new WarehouseMovementResource($result['movement_out']),
                'movement_in' => new WarehouseMovementResource($result['movement_in']),
                'avg_price' => $result['avg_price'],
            ], trans_message('warehouse_basic.transfer_success'));
            
        } catch (InvalidArgumentException $exception) {
            return $this->warehouseError('transfer_validation', $exception, $request, 'warehouse_basic.operation_validation_error', 422);
        } catch (Throwable $exception) {
            return $this->warehouseError('transfer', $exception, $request, 'warehouse_basic.transfer_error');
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
                    'reason' => $validated['reason'] ?? trans_message('warehouse_basic.reserve_default_reason'),
                    'metadata' => $validated['metadata'] ?? [],
                ]
            );

            return AdminResponse::success(null, trans_message('warehouse_basic.reserve_success'));
            
        } catch (InvalidArgumentException $exception) {
            return $this->warehouseError('reserve_validation', $exception, $request, 'warehouse_basic.operation_validation_error', 422);
        } catch (Throwable $exception) {
            return $this->warehouseError('reserve', $exception, $request, 'warehouse_basic.reserve_error');
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
            $result = $this->warehouseService->releaseReservedAssets(
                $organizationId,
                $validated['warehouse_id'],
                $validated['material_id'],
                $validated['quantity'],
                [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'reason' => $validated['reason'] ?? trans_message('warehouse_basic.unreserve_default_reason'),
                    'metadata' => $validated['metadata'] ?? [],
                ]
            );

            return AdminResponse::success($result, trans_message('warehouse_basic.unreserve_success'));
            
        } catch (InvalidArgumentException $exception) {
            return $this->warehouseError('unreserve_validation', $exception, $request, 'warehouse_basic.operation_validation_error', 422);
        } catch (Throwable $exception) {
            return $this->warehouseError('unreserve', $exception, $request, 'warehouse_basic.unreserve_error');
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
                $request->user()->id,
                $validated['project_id'] ?? null,
                $validated['document_number'] ?? null,
                $validated['reason'] ?? null
            );

            return AdminResponse::success($result, trans_message('warehouse_basic.transfer_to_contractor_success'));

        } catch (Throwable $exception) {
            return $this->warehouseError('transfer_to_contractor', $exception, $request, 'warehouse_basic.transfer_to_contractor_error');
        }
    }

    private function warehouseError(
        string $operation,
        Throwable $exception,
        Request $request,
        string $messageKey,
        int $status = 500,
        array $context = []
    ): JsonResponse {
        Log::error('Warehouse operation failed', array_merge($context, [
            'operation' => $operation,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'exception' => $exception,
        ]));

        $message = $status === 422 && $exception instanceof InvalidArgumentException && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : trans_message($messageKey);

        return AdminResponse::error($message, $status);
    }
}
