<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Material\StoreMaterialRequest;
use App\Http\Requests\Api\V1\Admin\Material\UpdateMaterialRequest;
use App\Http\Resources\Api\V1\Admin\MaterialResource;
use App\Http\Resources\Api\V1\Admin\MaterialCollection;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Services\Material\MaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;
use App\Models\Material;
use App\Models\WorkType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MaterialController extends Controller
{
    protected MaterialService $materialService;

    public function __construct(MaterialService $materialService)
    {
        $this->materialService = $materialService;
        $this->middleware('can:access-admin-panel')->except('getMeasurementUnits');
    }

    /**
     * Display a paginated list of materials with filtering and sorting.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $materials = $this->materialService->getMaterialsPaginated($request, (int)$perPage);
            return MaterialResource::collection($materials);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@index', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении списка материалов.'], 500);
        }
    }

    public function store(StoreMaterialRequest $request): MaterialResource | JsonResponse
    {
        try {
            $material = $this->materialService->createMaterial($request->validated(), $request);
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@store', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при создании материала.'], 500);
        }
    }

    public function show(Request $request, string $id): MaterialResource | JsonResponse
    {
        try {
            $material = $this->materialService->findMaterialById((int)$id, $request);
            if (!$material) {
                return response()->json(['success' => false, 'message' => 'Материал не найден.'], 404);
            }
            
            // Включаем нормы списания, если запрошено
            if ($request->has('include_consumption_rates')) {
                $material->setAttribute('include_consumption_rates', true);
            }
            
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@show', ['id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении материала.'], 500);
        }
    }

    public function update(UpdateMaterialRequest $request, string $id): MaterialResource | JsonResponse
    {
        try {
            $updatedSuccessfully = $this->materialService->updateMaterial((int)$id, $request->validated(), $request);
            
            if (!$updatedSuccessfully) { 
                return response()->json(['success' => false, 'message' => 'Материал не найден или не удалось обновить.'], 404);
            }
            
            // После успешного обновления, снова получаем модель, чтобы вернуть актуальные данные
            $material = $this->materialService->findMaterialById((int)$id, $request);
            if (!$material) { // На всякий случай, если материал исчез между update и find
                return response()->json(['success' => false, 'message' => 'Материал не найден после обновления.'], 404);
            }
            
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@update', ['id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при обновлении материала.'], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->materialService->deleteMaterial((int)$id, $request);
            if (!$success) {
                return response()->json(['success' => false, 'message' => 'Материал не найден или не удалось удалить.'], 404);
            }
            return response()->json(['success' => true, 'message' => 'Материал успешно удален.'], 200);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@destroy', ['id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при удалении материала.'], 500);
        }
    }

    public function getMaterialBalances(int $id, Request $request): JsonResponse
    {
        try {
            $balances = $this->materialService->getMaterialBalancesByMaterial(
                $id,
                $request->get('per_page', 15),
                $request->get('project_id'),
                $request->get('sort_by', 'created_at'),
                $request->get('sort_direction', 'desc')
            );
            return response()->json(['success' => true, 'data' => $balances]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@getMaterialBalances', ['id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении балансов материала.'], 500);
        }
    }

    public function getMeasurementUnits(Request $request): JsonResponse
    {
        try {
            $units = $this->materialService->getMeasurementUnits($request);
            if (is_array($units) && isset($units['success']) && $units['success'] === false) {
                return response()->json($units, isset($units['code']) ? $units['code'] : 400);
            }
            return response()->json(['success' => true, 'data' => $units]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@getMeasurementUnits', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении единиц измерения.'], 500);
        }
    }

    public function importMaterials(Request $request): JsonResponse
    {
        try {
            // Расширяем валидацию, добавляя поддержку формата импорта
            $validatedData = $this->validate($request, [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
                'format' => 'nullable|string|in:simple,sbis,onec',
                'options' => 'nullable|array',
            ]);

            $format = $validatedData['format'] ?? 'simple';
            $options = $validatedData['options'] ?? [];
            $options['organization_id'] = $request->user()?->current_organization_id;

            $result = $this->materialService->importMaterialsFromFile(
                $validatedData['file'], 
                $format, 
                $options
            );

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации файла.',
                'errors' => $e->errors(),
            ], 422);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@importMaterials', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при импорте материалов.'], 500);
        }
    }

    /**
     * Получить нормы списания для материала.
     */
    public function getConsumptionRates(Request $request, int $id): JsonResponse
    {
        try {
            $material = $this->materialService->findMaterialById($id, $request);
            if (!$material) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Материал не найден.'
                ], 404);
            }

            $rates = $material->getConsumptionRatesWithWorkTypes();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'material_id' => $material->id,
                    'material_name' => $material->name,
                    'consumption_rates' => $rates
                ]
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@getConsumptionRates', [
                'id' => $id, 
                'message' => $e->getMessage(), 
                'file' => $e->getFile(), 
                'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении норм списания материала.'
            ], 500);
        }
    }

    /**
     * Обновить нормы списания для материала.
     */
    public function updateConsumptionRates(Request $request, int $id): JsonResponse
    {
        try {
            // Валидация входящих данных
            $validated = $request->validate([
                'consumption_rates' => 'required|array',
                'consumption_rates.*' => 'numeric|min:0',
            ], [
                'consumption_rates.required' => 'Необходимо указать нормы списания.',
                'consumption_rates.array' => 'Нормы списания должны быть представлены в виде массива.',
                'consumption_rates.*.numeric' => 'Нормы списания должны быть числовыми значениями.',
                'consumption_rates.*.min' => 'Нормы списания не могут быть отрицательными.',
            ]);

            $material = $this->materialService->findMaterialById($id, $request);
            if (!$material) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Материал не найден.'
                ], 404);
            }

            // Проверяем существование видов работ
            $workTypeIds = array_keys($validated['consumption_rates']);
            $existingWorkTypeCount = WorkType::whereIn('id', $workTypeIds)->count();
            
            if (count($workTypeIds) != $existingWorkTypeCount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некоторые виды работ не найдены.'
                ], 422);
            }

            // Обновляем нормы списания
            $material->consumption_rates = $validated['consumption_rates'];
            $material->save();

            // Возвращаем обновленные данные
            return response()->json([
                'success' => true,
                'message' => 'Нормы списания успешно обновлены.',
                'data' => [
                    'material_id' => $material->id,
                    'material_name' => $material->name,
                    'consumption_rates' => $material->getConsumptionRatesWithWorkTypes()
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации данных.',
                'errors' => $e->errors(),
            ], 422);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@updateConsumptionRates', [
                'id' => $id, 
                'message' => $e->getMessage(), 
                'file' => $e->getFile(), 
                'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при обновлении норм списания материала.'
            ], 500);
        }
    }

    /**
     * Проверить валидность данных материала для интеграции с СБИС/1С.
     */
    public function validateForAccounting(Request $request, int $id): JsonResponse
    {
        try {
            $material = $this->materialService->findMaterialById($id, $request);
            if (!$material) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Материал не найден.'
                ], 404);
            }

            // Проверка на наличие необходимых полей для интеграции
            $validationErrors = [];
            
            if (empty($material->external_code)) {
                $validationErrors[] = 'Не указан внешний код материала для интеграции.';
            }
            
            if (empty($material->sbis_nomenclature_code)) {
                $validationErrors[] = 'Не указан код номенклатуры СБИС.';
            }
            
            // Проверка соответствия единиц измерения
            if (empty($material->sbis_unit_code)) {
                $validationErrors[] = 'Не указан код единицы измерения СБИС.';
            }
            
            // Проверка счета учета
            if (empty($material->accounting_account)) {
                $validationErrors[] = 'Не указан счет учета в бухгалтерии.';
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'material_id' => $material->id,
                    'material_name' => $material->name,
                    'is_valid' => empty($validationErrors),
                    'validation_errors' => $validationErrors,
                    'accounting_data' => [
                        'external_code' => $material->external_code,
                        'sbis_nomenclature_code' => $material->sbis_nomenclature_code,
                        'sbis_unit_code' => $material->sbis_unit_code,
                        'accounting_account' => $material->accounting_account,
                        'use_in_accounting_reports' => $material->use_in_accounting_reports,
                    ]
                ]
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@validateForAccounting', [
                'id' => $id, 
                'message' => $e->getMessage(), 
                'file' => $e->getFile(), 
                'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при проверке материала для интеграции.'
            ], 500);
        }
    }

    /**
     * Скачать шаблон для импорта материалов (xlsx)
     */
    public function downloadImportTemplate(): StreamedResponse
    {
        $spreadsheet = $this->materialService->generateImportTemplate();
        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="materials_import_template.xlsx"');
        return $response;
    }
} 