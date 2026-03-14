<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehousePhotoUploadRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePhotoService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehousePhotoController extends Controller
{
    public function __construct(
        private readonly WarehousePhotoService $warehousePhotoService
    ) {
    }

    public function assetPhotos(Request $request, int $assetId): JsonResponse
    {
        try {
            $asset = $this->warehousePhotoService->getAsset(
                (int) $request->user()->current_organization_id,
                $assetId
            );

            return AdminResponse::success($asset->photo_gallery);
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::assetPhotos', [
                'asset_id' => $assetId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
        }
    }

    public function uploadAssetPhotos(WarehousePhotoUploadRequest $request, int $assetId): JsonResponse
    {
        try {
            $photos = $this->warehousePhotoService->uploadAssetPhotos(
                (int) $request->user()->current_organization_id,
                $assetId,
                $request->file('photos', []),
                $request->user()
            );

            return AdminResponse::success($photos, __('warehouse_basic.photo_upload_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::uploadAssetPhotos', [
                'asset_id' => $assetId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function deleteAssetPhoto(Request $request, int $assetId, int $fileId): JsonResponse
    {
        try {
            $this->warehousePhotoService->deleteAssetPhoto(
                (int) $request->user()->current_organization_id,
                $assetId,
                $fileId
            );

            return AdminResponse::success(null, __('warehouse_basic.photo_delete_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::deleteAssetPhoto', [
                'asset_id' => $assetId,
                'file_id' => $fileId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
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

            return AdminResponse::success($gallery->photo_gallery);
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::balancePhotos', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
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

            return AdminResponse::success($photos, __('warehouse_basic.photo_upload_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::uploadBalancePhotos', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
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

            return AdminResponse::success(null, __('warehouse_basic.photo_delete_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::deleteBalancePhoto', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'file_id' => $fileId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
        }
    }

    public function movementPhotos(Request $request, int $movementId): JsonResponse
    {
        try {
            $movement = $this->warehousePhotoService->getMovement(
                (int) $request->user()->current_organization_id,
                $movementId
            );

            return AdminResponse::success($movement->photo_gallery);
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::movementPhotos', [
                'movement_id' => $movementId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
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

            return AdminResponse::success($photos, __('warehouse_basic.photo_upload_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::uploadMovementPhotos', [
                'movement_id' => $movementId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
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

            return AdminResponse::success(null, __('warehouse_basic.photo_delete_success'));
        } catch (\Throwable $exception) {
            Log::error('WarehousePhotoController::deleteMovementPhoto', [
                'movement_id' => $movementId,
                'file_id' => $fileId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(__('warehouse_basic.photo_target_not_found'), 404);
        }
    }
}
