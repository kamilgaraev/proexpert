<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\CreateSerializedAssetInstancesRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ExportAssetLabelsRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\IssueSerializedAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReturnSerializedAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\StoreAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UpdateAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetLabelExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\BusinessModules\Features\BasicWarehouse\Services\SerializedAssetReceiptService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class AssetController extends Controller
{
    public function __construct(
        protected AssetService $assetService,
        protected AssetLabelExportService $assetLabelExportService,
        protected SerializedAssetReceiptService $serializedAssets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $filters = array_filter([
                'asset_type' => $request->input('asset_type'),
                'asset_category' => $request->input('asset_category'),
                'warehouse_id' => $request->integer('warehouse_id') ?: null,
                'search' => $request->input('search', $request->input('q')),
                'sort_by' => $request->input('sort_by', 'name'),
                'sort_order' => $request->input('sort_order', 'asc'),
            ], fn ($value) => $value !== null);

            $perPage = (int) $request->input('per_page', 15);
            $assets = $this->assetService->getAssets($organizationId, $filters, $perPage);

            return $this->paginatedResponse($assets);
        } catch (\Exception $exception) {
            Log::error('AssetController::index error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.list_error'), 500);
        }
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $asset = $this->assetService->createAsset($organizationId, $request->validated());

            return AdminResponse::success(
                $this->assetService->getAssetById($organizationId, $asset->id),
                trans_message('basic_warehouse.asset.created'),
                201
            );
        } catch (\Exception $exception) {
            Log::error('AssetController::store error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'data' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.create_error').': '.$exception->getMessage(), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $asset = $this->assetService->getAssetById($organizationId, $id);

            return AdminResponse::success($asset);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.asset.not_found'), 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::show error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.show_error'), 500);
        }
    }

    public function update(UpdateAssetRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $asset = $this->assetService->updateAsset($organizationId, $id, $request->validated());

            return AdminResponse::success(
                $this->assetService->getAssetById($organizationId, $asset->id),
                trans_message('basic_warehouse.asset.updated')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.asset.not_found'), 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::update error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'data' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.update_error').': '.$exception->getMessage(), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $this->assetService->deactivateAsset($organizationId, $id);

            return AdminResponse::success(null, trans_message('basic_warehouse.asset.deactivated'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.asset.not_found'), 404);
        } catch (\Exception $exception) {
            Log::error('AssetController::destroy error', [
                'user_id' => $request->user()->id ?? null,
                'asset_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.deactivate_error'), 500);
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

            return AdminResponse::error(trans_message('basic_warehouse.asset.types_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $warehouseId = $request->integer('warehouse_id') ?: null;
            $stats = $this->assetService->getAssetTypeStatistics($organizationId, $warehouseId);

            return AdminResponse::success($stats);
        } catch (\Exception $exception) {
            Log::error('AssetController::statistics error', [
                'organization_id' => $request->user()->current_organization_id ?? null,
                'user_id' => $request->user()->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.asset.statistics_error'), 500);
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

    public function createInstances(CreateSerializedAssetInstancesRequest $request, int $id): JsonResponse
    {
        try {
            $instances = $this->serializedAssets->receive(
                (int) $request->user()->current_organization_id,
                $id,
                (int) $request->validated('warehouse_id'),
                (int) $request->user()->id,
                $request->validated('instances'),
            );

            return AdminResponse::success(
                $instances,
                trans_message('basic_warehouse.serialized.instances_created'),
                201,
            );
        } catch (DomainException|QueryException $exception) {
            return AdminResponse::error($exception instanceof DomainException
                ? $exception->getMessage()
                : trans_message('asset_management.errors.duplicate_identity'), 422);
        } catch (\Throwable $exception) {
            Log::error('AssetController::createInstances error', [
                'organization_id' => $request->user()->current_organization_id,
                'user_id' => $request->user()->id,
                'material_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.serialized.operation_failed'), 500);
        }
    }

    public function organizationAssets(Request $request): JsonResponse
    {
        $filters = array_filter([
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'project_id' => $request->integer('project_id') ?: null,
            'responsible_user_id' => $request->integer('responsible_user_id') ?: null,
            'material_id' => $request->integer('material_id') ?: null,
            'search' => $request->string('search')->trim()->value() ?: null,
        ], static fn (mixed $value): bool => $value !== null);
        $paginator = $this->serializedAssets->paginate(
            (int) $request->user()->current_organization_id,
            $filters,
            $request->integer('per_page', 20),
        );

        return $this->paginatedResponse($paginator);
    }

    public function issueInstance(IssueSerializedAssetRequest $request, int $id): JsonResponse
    {
        try {
            $asset = $this->serializedAssets->issue(
                (int) $request->user()->current_organization_id,
                $id,
                (int) $request->user()->id,
                $request->validated(),
            );

            return AdminResponse::success($asset, trans_message('basic_warehouse.serialized.issued'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function returnInstance(ReturnSerializedAssetRequest $request, int $id): JsonResponse
    {
        try {
            $asset = $this->serializedAssets->returnToWarehouse(
                (int) $request->user()->current_organization_id,
                $id,
                (int) $request->user()->id,
                $request->validated(),
            );

            return AdminResponse::success($asset, trans_message('basic_warehouse.serialized.returned'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    private function paginatedResponse(LengthAwarePaginator $paginator): JsonResponse
    {
        return AdminResponse::paginated(
            $paginator->items(),
            [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            null
        );
    }
}
