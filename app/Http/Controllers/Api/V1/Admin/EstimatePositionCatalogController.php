<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\EstimatePositionCatalog\EstimatePositionCatalogService;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\StoreEstimatePositionRequest;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\UpdateEstimatePositionRequest;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\EstimatePositionResource;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\EstimatePositionCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimatePositionCatalogController extends Controller
{
    public function __construct(
        private readonly EstimatePositionCatalogService $service
    ) {}

    /**
     * Получить список позиций
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $perPage = (int) $request->input('per_page', 15);
            $sortBy = $request->input('sort_by', 'name');
            $sortDirection = $request->input('sort_direction', 'asc');

            $filters = $request->only([
                'category_id',
                'item_type',
                'is_active',
                'search',
            ]);

            $positions = $this->service->getAllPositions(
                $organizationId,
                $perPage,
                $filters,
                $sortBy,
                $sortDirection
            );

            return response()->json(new EstimatePositionCollection($positions));
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Показать конкретную позицию
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $position = $this->service->getPositionById($id, $organizationId);

            if (!$position) {
                return AdminResponse::error(
                    trans_message('estimate.catalog_position_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success(new EstimatePositionResource($position));
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_position_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Создать новую позицию
     */
    public function store(StoreEstimatePositionRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $userId = $request->user()->id;

            $position = $this->service->createPosition(
                $organizationId,
                $userId,
                $request->validated()
            );

            return AdminResponse::success(
                new EstimatePositionResource($position),
                trans_message('estimate.catalog_position_created'),
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_position_create_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Обновить позицию
     */
    public function update(UpdateEstimatePositionRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $userId = $request->user()->id;

            $position = $this->service->updatePosition(
                $id,
                $organizationId,
                $request->validated(),
                $userId
            );

            return AdminResponse::success(
                new EstimatePositionResource($position),
                trans_message('estimate.catalog_position_updated')
            );
        } catch (\RuntimeException $e) {
            return AdminResponse::error(
                $e->getMessage(),
                Response::HTTP_NOT_FOUND
            );
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_position_update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Удалить позицию
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $this->service->deletePosition($id, $organizationId);

            return AdminResponse::success(null, trans_message('estimate.catalog_position_deleted'));
        } catch (\DomainException $e) {
            return AdminResponse::error(
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\RuntimeException $e) {
            return AdminResponse::error(
                $e->getMessage(),
                Response::HTTP_NOT_FOUND
            );
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_position_delete_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Поиск позиций
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $query = $request->input('q', '');

            if (empty($query)) {
                return AdminResponse::error(
                    trans_message('estimate.catalog_search_query_empty'),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $filters = $request->only(['category_id', 'item_type', 'is_active']);

            $positions = $this->service->search($organizationId, $query, $filters);

            return response()->json(new EstimatePositionCollection($positions));
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.search.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.catalog_search_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}

