<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ExportAssetLabelsRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\StoreAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UpdateAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetLabelExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssetController extends Controller
{
    public function __construct(
        protected AssetService $assetService,
        protected AssetLabelExportService $assetLabelExportService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $filters = array_filter([
                'asset_type' => $request->input('asset_type'),
                'asset_category' => $request->input('asset_category'),
                'search' => $request->input('search'),
                'sort_by' => $request->input('sort_by', 'name'),
                'sort_order' => $request->input('sort_order', 'asc'),
            ], fn ($value) => $value !== null);

            $perPage = (int) $request->input('per_page', 15);
            $assets = $this->assetService->getAssets($organizationId, $filters, $perPage);

            return AdminResponse::success($assets);
        } catch (\Exception $exception) {
            Log::error('AssetController::index error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка получения списка активов', 500);
        }
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $asset = $this->assetService->createAsset($organizationId, $request->validated());

            return AdminResponse::success(
                $this->assetService->getAssetById($asset->id),
                'Актив успешно создан',
                201
            );
        } catch (\Exception $exception) {
            Log::error('AssetController::store error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'data' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка создания актива: ' . $exception->getMessage(), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $asset = $this->assetService->getAssetById($id);

            return AdminResponse::success($asset);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error('Актив не найден', 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::show error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка получения актива', 500);
        }
    }

    public function update(UpdateAssetRequest $request, int $id): JsonResponse
    {
        try {
            $asset = $this->assetService->updateAsset($id, $request->validated());

            return AdminResponse::success(
                $this->assetService->getAssetById($asset->id),
                'Актив успешно обновлён'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error('Актив не найден', 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::update error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'data' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка обновления актива: ' . $exception->getMessage(), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->assetService->deactivateAsset($id);

            return AdminResponse::success(null, 'Актив деактивирован');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error('Актив не найден', 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::destroy error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка деактивации актива', 500);
        }
    }

    public function types(): JsonResponse
    {
        try {
            return AdminResponse::success(Asset::getAssetTypes());
        } catch (\Exception $exception) {
            Log::error('AssetController::types error', [
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка получения типов активов', 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $stats = $this->assetService->getAssetTypeStatistics($organizationId);

            return AdminResponse::success($stats);
        } catch (\Exception $exception) {
            Log::error('AssetController::statistics error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error('Ошибка получения статистики', 500);
        }
    }

    public function exportLabelsPdf(ExportAssetLabelsRequest $request)
    {
        try {
            $organizationId = (int) $request->user()->current_organization_id;

            return $this->assetLabelExportService->export($organizationId, $request->validated());
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Exception $exception) {
            Log::error('AssetController::exportLabelsPdf error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.labels_export_error'), 500);
        }
    }
}
