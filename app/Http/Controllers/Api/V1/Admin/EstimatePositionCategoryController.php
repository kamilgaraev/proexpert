<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstimatePositionCatalog\CategoryService;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\CategoryResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function trans_message;

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

            return AdminResponse::success(CategoryResource::collection($categories));
        } catch (\Exception $e) {
            Log::error('estimate_position_category.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.categories_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success($tree);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.tree.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.category_tree_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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
                return AdminResponse::error(
                    trans_message('estimate.category_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success(new CategoryResource($category));
        } catch (\Exception $e) {
            Log::error('estimate_position_category.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.category_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success(
                new CategoryResource($category),
                trans_message('estimate.category_created'),
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            Log::error('estimate_position_category.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.category_create_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success(
                new CategoryResource($category),
                trans_message('estimate.category_updated')
            );
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.category_update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success(null, trans_message('estimate.category_deleted'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('estimate_position_category.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.category_delete_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success(null, trans_message('estimate.categories_reordered'));
        } catch (\Exception $e) {
            Log::error('estimate_position_category.reorder.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.categories_reorder_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
