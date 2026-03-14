<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReceiptRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehousePhotoUploadRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePhotoService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\Material;
use App\Services\Mobile\MobileWarehouseService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseController extends Controller
{
    public function __construct(
        private readonly MobileWarehouseService $warehouseService,
        private readonly WarehouseService $basicWarehouseService,
        private readonly AssetService $assetService,
        private readonly WarehousePhotoService $warehousePhotoService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_warehouse.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->warehouseService->build($user));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    public function balances(Request $request, int $warehouseId): JsonResponse
    {
        try {
            return MobileResponse::success(
                $this->basicWarehouseService->getStockData(
                    (int) $request->user()->current_organization_id,
                    ['warehouse_id' => $warehouseId]
                )
            );
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.balances.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'warehouse_id' => $warehouseId,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    public function materialAutocomplete(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->user()->current_organization_id;
            $query = trim((string) $request->query('q', ''));
            $limit = min(20, max(1, (int) $request->query('limit', 10)));

            if ($query === '') {
                return MobileResponse::success([]);
            }

            $materials = Material::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->with('measurementUnit:id,name,short_name')
                ->where(function ($builder) use ($query): void {
                    $builder->where('name', 'like', '%' . $query . '%')
                        ->orWhere('code', 'like', '%' . $query . '%');
                })
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(static fn (Material $material): array => [
                    'id' => $material->id,
                    'name' => $material->name,
                    'code' => $material->code,
                    'default_price' => (float) ($material->default_price ?? 0),
                    'measurement_unit' => $material->measurementUnit ? [
                        'id' => $material->measurementUnit->id,
                        'name' => $material->measurementUnit->name,
                        'short_name' => $material->measurementUnit->short_name,
                    ] : null,
                ])
                ->values()
                ->all();

            return MobileResponse::success($materials);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.material_autocomplete.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    public function receipt(ReceiptRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $organizationId = (int) $request->user()->current_organization_id;

            $materialId = $validated['material_id'] ?? null;

            if (!$materialId && isset($validated['material'])) {
                $asset = $this->assetService->createAsset($organizationId, [
                    'name' => $validated['material']['name'],
                    'code' => $validated['material']['code'] ?? null,
                    'measurement_unit_id' => $validated['material']['measurement_unit_id'],
                    'category' => $validated['material']['category'] ?? null,
                    'default_price' => $validated['material']['default_price'] ?? $validated['price'],
                    'description' => $validated['material']['description'] ?? null,
                    'asset_type' => $validated['material']['asset_type'] ?? 'material',
                    'is_active' => true,
                ]);

                $materialId = $asset->id;
            }

            if (!$materialId) {
                return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 422);
            }

            $result = $this->basicWarehouseService->receiveAsset(
                $organizationId,
                (int) $validated['warehouse_id'],
                (int) $materialId,
                (float) $validated['quantity'],
                (float) $validated['price'],
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

            return MobileResponse::success([
                'movement_id' => $result['movement']->id,
                'photo_gallery' => $result['movement']->photo_gallery,
            ], __('warehouse_basic.receipt_success'));
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.receipt.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error($exception->getMessage(), 422);
        }
    }

    public function balancePhotos(Request $request, int $warehouseId, int $materialId): JsonResponse
    {
        try {
            $gallery = $this->warehousePhotoService->getBalanceGallery(
                (int) $request->user()->current_organization_id,
                $warehouseId,
                $materialId
            );

            return MobileResponse::success($gallery->photo_gallery);
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        }
    }

    public function uploadBalancePhotos(
        WarehousePhotoUploadRequest $request,
        int $warehouseId,
        int $materialId
    ): JsonResponse {
        try {
            $photos = $this->warehousePhotoService->uploadBalancePhotos(
                (int) $request->user()->current_organization_id,
                $warehouseId,
                $materialId,
                $request->file('photos', []),
                $request->user()
            );

            return MobileResponse::success($photos, __('warehouse_basic.photo_upload_success'));
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        }
    }

    public function deleteBalancePhoto(Request $request, int $warehouseId, int $materialId, int $fileId): JsonResponse
    {
        try {
            $this->warehousePhotoService->deleteBalancePhoto(
                (int) $request->user()->current_organization_id,
                $warehouseId,
                $materialId,
                $fileId
            );

            return MobileResponse::success(null, __('warehouse_basic.photo_delete_success'));
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        }
    }

    public function movementPhotos(Request $request, int $movementId): JsonResponse
    {
        try {
            $movement = $this->warehousePhotoService->getMovement(
                (int) $request->user()->current_organization_id,
                $movementId
            );

            return MobileResponse::success($movement->photo_gallery);
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        }
    }

    public function uploadMovementPhotos(WarehousePhotoUploadRequest $request, int $movementId): JsonResponse
    {
        try {
            $photos = $this->warehousePhotoService->uploadMovementPhotos(
                (int) $request->user()->current_organization_id,
                $movementId,
                $request->file('photos', []),
                $request->user()
            );

            return MobileResponse::success($photos, __('warehouse_basic.photo_upload_success'));
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        }
    }

    public function deleteMovementPhoto(Request $request, int $movementId, int $fileId): JsonResponse
    {
        try {
            $this->warehousePhotoService->deleteMovementPhoto(
                (int) $request->user()->current_organization_id,
                $movementId,
                $fileId
            );

            return MobileResponse::success(null, __('warehouse_basic.photo_delete_success'));
        } catch (\Throwable $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        }
    }
}
