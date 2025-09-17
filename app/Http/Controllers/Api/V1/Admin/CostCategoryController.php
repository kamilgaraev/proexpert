<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
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
            return CostCategoryResource::collection($costCategories);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@index', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении списка категорий затрат.',
            ], 500);
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
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@store', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при создании категории затрат.',
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Категория затрат не найдена в вашей организации.'
                ], 404);
            }
            
            // Загружаем связанные данные
            $costCategory->load(['parent', 'children']);
            
            return new CostCategoryResource($costCategory);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@show', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении категории затрат.',
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Категория затрат не найдена или не удалось обновить.'
                ], 404);
            }
            
            // Загружаем связанные данные
            $costCategory->load(['parent', 'children']);
            
            return new CostCategoryResource($costCategory);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@update', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при обновлении категории затрат.',
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Категория затрат не найдена или не удалось удалить.'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Категория затрат успешно удалена.'
            ], 200);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@destroy', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при удалении категории затрат.',
            ], 500);
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
            
            return response()->json($importResult);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in CostCategoryController@import', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при импорте категорий затрат.',
            ], 500);
        }
    }
}
