<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstimatePositionCatalog\EstimatePositionCatalogService;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\StoreEstimatePositionRequest;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\UpdateEstimatePositionRequest;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\EstimatePositionResource;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\EstimatePositionCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить позиции',
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'error' => 'Позиция не найдена',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new EstimatePositionResource($position),
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить позицию',
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Позиция успешно создана',
                'data' => new EstimatePositionResource($position),
            ], 201);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать позицию',
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Позиция успешно обновлена',
                'data' => new EstimatePositionResource($position),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить позицию',
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Позиция успешно удалена',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить позицию',
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'error' => 'Поисковый запрос не может быть пустым',
                ], 422);
            }

            $filters = $request->only(['category_id', 'item_type', 'is_active']);

            $positions = $this->service->search($organizationId, $query, $filters);

            return response()->json(new EstimatePositionCollection($positions));
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.search.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ошибка при поиске позиций',
            ], 500);
        }
    }
}

