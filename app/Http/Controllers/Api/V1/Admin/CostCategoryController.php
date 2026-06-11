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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CostCategoryController extends Controller
{
    protected CostCategoryService $costCategoryService;

    public function __construct(CostCategoryService $costCategoryService)
    {
        $this->costCategoryService = $costCategoryService;
        $this->middleware('authorize:cost_categories.view')->only(['index', 'show']);
        $this->middleware('authorize:cost_categories.create')->only(['store']);
        $this->middleware('authorize:cost_categories.edit')->only(['update']);
        $this->middleware('authorize:cost_categories.delete')->only(['destroy']);
        $this->middleware('authorize:cost_categories.import')->only(['import']);
    }

    /**
     * Получить список категорий затрат с пагинацией.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $this->normalizePerPage($request->query('per_page', 15));
            $costCategories = $this->costCategoryService->getCostCategoriesForCurrentOrg($request, (int)$perPage);
            return AdminResponse::paginated(
                CostCategoryResource::collection($costCategories),
                [
                    'current_page' => $costCategories->currentPage(),
                    'last_page' => $costCategories->lastPage(),
                    'per_page' => $costCategories->perPage(),
                    'total' => $costCategories->total(),
                ]
            );
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
    public function store(StoreCostCategoryRequest $request): JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->createCostCategory($request->validated(), $request);
            return AdminResponse::success(new CostCategoryResource($costCategory->load('parent')), null, Response::HTTP_CREATED);
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
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->findCostCategoryByIdForCurrentOrg((int)$id, $request);
            
            if (!$costCategory) {
                return AdminResponse::error(trans_message('cost_category.not_found'), 404);
            }
            
            $costCategory->load(['parent', 'children']);
            
            return AdminResponse::success(new CostCategoryResource($costCategory));
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
    public function update(UpdateCostCategoryRequest $request, string $id): JsonResponse
    {
        try {
            $costCategory = $this->costCategoryService->updateCostCategory((int)$id, $request->validated(), $request);
            
            if (!$costCategory) {
                return AdminResponse::error(trans_message('cost_category.update_failed'), 404);
            }
            
            $costCategory->load(['parent', 'children']);
            
            return AdminResponse::success(new CostCategoryResource($costCategory));
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
            
            $format = $validatedData['format'] ?? 'simple';
            $categoriesData = $this->extractCategoriesFromFile($validatedData['file'], $format);
            
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

    private function extractCategoriesFromFile(UploadedFile $file, string $format): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            throw new BusinessLogicException(trans_message('cost_category.import_empty'), 422);
        }

        $headers = array_map(
            fn ($value): ?string => $this->normalizeImportHeader((string) $value, $format),
            array_shift($rows)
        );

        $categories = [];
        foreach ($rows as $row) {
            $category = [];

            foreach ($row as $column => $value) {
                $field = $headers[$column] ?? null;
                if (!$field || $value === null || $value === '') {
                    continue;
                }

                $category[$field] = is_string($value) ? trim($value) : $value;
            }

            if ($category !== []) {
                $categories[] = $category;
            }
        }

        if ($categories === []) {
            throw new BusinessLogicException(trans_message('cost_category.import_empty'), 422);
        }

        return $categories;
    }

    private function normalizeImportHeader(string $header, string $format): ?string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = str_replace([' ', '-', '.'], '_', $normalized);

        $maps = [
            'simple' => [
                'name' => 'name',
                'название' => 'name',
                'наименование' => 'name',
                'code' => 'code',
                'код' => 'code',
                'external_code' => 'external_code',
                'внешний_код' => 'external_code',
                'description' => 'description',
                'описание' => 'description',
                'parent_external_code' => 'parent_external_code',
                'код_родителя' => 'parent_external_code',
                'parent_code' => 'parent_external_code',
                'is_active' => 'is_active',
                'активна' => 'is_active',
                'sort_order' => 'sort_order',
                'порядок' => 'sort_order',
            ],
            'sbis' => [
                'наименование' => 'name',
                'код' => 'external_code',
                'родитель' => 'parent_external_code',
                'комментарий' => 'description',
            ],
            'onec' => [
                'наименование' => 'name',
                'код' => 'external_code',
                'родитель' => 'parent_external_code',
                'описание' => 'description',
            ],
        ];

        return ($maps[$format] ?? $maps['simple'])[$normalized]
            ?? $maps['simple'][$normalized]
            ?? null;
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $value = (int) $perPage;

        if ($value <= 0) {
            return 1000;
        }

        return min($value, 1000);
    }
}
