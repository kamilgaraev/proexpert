<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Requests\Api\V1\Admin\CostCategory\StoreCostCategoryRequest;
use App\Http\Requests\Api\V1\Admin\CostCategory\UpdateCostCategoryRequest;
use App\Http\Resources\Api\V1\Admin\CostCategory\CostCategoryResource;
use App\Services\CostCategory\CostCategoryService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class CostCategoryController extends Controller
{
    protected CostCategoryService $costCategoryService;

    public function __construct(CostCategoryService $costCategoryService)
    {
        $this->costCategoryService = $costCategoryService;
        $this->middleware('can:admin.catalogs.manage');
    }

    /**
     * Получить список категорий затрат с пагинацией.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $costCategories = $this->costCategoryService->getCostCategoriesForCurrentOrg($request, (int)$perPage);
            return AdminResponse::success(CostCategoryResource::collection($costCategories));
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@index BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_list'), 500);
        }
    }

    /**
     * Создать новую категорию затрат.
     */
    public function store(StoreCostCategoryRequest $request): CostCategoryResource | JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->createCostCategory($request->validated(), $request);
            return new CostCategoryResource($costCategory->load('parent'));
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@store BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@store Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_create'), 500);
        }
    }

    /**
     * Получить информацию о конкретной категории затрат.
     */
    public function show(Request $request, string $id): CostCategoryResource | JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->findCostCategoryByIdForCurrentOrg((int)$id, $request);
            
            if (!$costCategory) {
                return AdminResponse::error(trans_message('cost_category.not_found'), 404);
            }
            
            $costCategory->load(['parent', 'children']);
            
            return new CostCategoryResource($costCategory);
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@show BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_get'), 500);
        }
    }

    /**
     * Обновить категорию затрат.
     */
    public function update(UpdateCostCategoryRequest $request, string $id): CostCategoryResource | JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->updateCostCategory((int)$id, $request->validated(), $request);
            
            if (!$costCategory) {
                return AdminResponse::error(trans_message('cost_category.update_failed'), 404);
            }
            
            $costCategory->load(['parent', 'children']);
            
            return new CostCategoryResource($costCategory);
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@update BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_update'), 500);
        }
    }

    /**
     * Удалить категорию затрат.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->costCategoryService->deleteCostCategory((int)$id, $request);
            
            if (!$success) {
                return AdminResponse::error(trans_message('cost_category.delete_failed'), 404);
            }
            
            return AdminResponse::success(null, trans_message('cost_category.deleted'));
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@destroy BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_delete'), 500);
        }
    }
    
    /**
     * Импортировать категории затрат из файла.
     */
    public function import(Request $request): JsonResponse
    {
        try {
            // Валидация входящих данных
            $validatedData = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
                'format' => 'nullable|string|in:simple,sbis,onec',
            ]);
            
            // Получаем формат импорта или используем 'simple' по умолчанию
            $format = $validatedData['format'] ?? 'simple';
            
            // TODO: Здесь должен быть код обработки файла и извлечения данных
            // в зависимости от указанного формата
            
            // Пример данных для тестирования
            $categoriesData = [
                [
                    'name' => 'Тестовая категория 1',
                    'code' => 'test-1',
                    'external_code' => 'ext-001',
                    'description' => 'Описание тестовой категории 1',
                ],
                [
                    'name' => 'Тестовая категория 2',
                    'code' => 'test-2',
                    'external_code' => 'ext-002',
                    'description' => 'Описание тестовой категории 2',
                    'parent_external_code' => 'ext-001',
                ],
            ];
            
            // Импортируем данные
            $importResult = $this->costCategoryService->importCostCategories(
                $categoriesData, 
                $request->user()->current_organization_id
            );
            
            if (isset($importResult['success']) && $importResult['success']) {
                return AdminResponse::success($importResult['data'] ?? $importResult, $importResult['message'] ?? trans_message('cost_category.import_success'));
            } else {
                return AdminResponse::error($importResult['message'] ?? trans_message('cost_category.internal_error_import'), $importResult['code'] ?? 400);
            }
        } catch (BusinessLogicException $e) {
            Log::error('CostCategoryController@import BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('CostCategoryController@import Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('cost_category.internal_error_import'), 500);
        }
    }
}
