<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstimatePositionCatalog\CategoryService;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EstimatePositionCategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $service
    ) {}

    /**
     * Получить список категорий
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $categories = $this->service->getAllCategories($organizationId);

            return response()->json([
                'success' => true,
                'data' => CategoryResource::collection($categories),
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить категории',
            ], 500);
        }
    }

    /**
     * Получить дерево категорий
     */
    public function tree(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $tree = $this->service->getCategoryTree($organizationId);

            return response()->json([
                'success' => true,
                'data' => $tree,
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.tree.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить дерево категорий',
            ], 500);
        }
    }

    /**
     * Показать конкретную категорию
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $category = $this->service->getCategoryById($id, $organizationId);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'error' => 'Категория не найдена',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new CategoryResource($category),
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить категорию',
            ], 500);
        }
    }

    /**
     * Создать новую категорию
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $category = $this->service->createCategory(
                $organizationId,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => new CategoryResource($category),
            ], 201);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать категорию',
            ], 500);
        }
    }

    /**
     * Обновить категорию
     */
    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $category = $this->service->updateCategory(
                $id,
                $organizationId,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно обновлена',
                'data' => new CategoryResource($category),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить категорию',
            ], 500);
        }
    }

    /**
     * Удалить категорию
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $this->service->deleteCategory($id, $organizationId);

            return response()->json([
                'success' => true,
                'message' => 'Категория успешно удалена',
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
            Log::error('estimate_position_category.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить категорию',
            ], 500);
        }
    }

    /**
     * Изменить порядок категорий
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $request->validate([
                'categories' => 'required|array',
                'categories.*.id' => 'required|integer',
                'categories.*.sort_order' => 'required|integer',
            ]);

            $this->service->reorderCategories(
                $organizationId,
                $request->input('categories')
            );

            return response()->json([
                'success' => true,
                'message' => 'Порядок категорий успешно изменен',
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.reorder.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось изменить порядок категорий',
            ], 500);
        }
    }
}

