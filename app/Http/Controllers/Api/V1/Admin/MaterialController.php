<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Material\StoreMaterialRequest;
use App\Http\Requests\Api\V1\Admin\Material\UpdateMaterialRequest;
use App\Http\Resources\Api\V1\Admin\MaterialResource;
use App\Http\Resources\Api\V1\Admin\MaterialCollection;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Http\Responses\AdminResponse;
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
        // Авторизация настроена на уровне роутов через middleware стек
    }

    private function getOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                $organizationId = $user->current_organization_id;
            }
        }
        
        return $organizationId ? (int) $organizationId : null;
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
            Log::error('MaterialController@index BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_list'), 500);
        }
    }

    public function store(StoreMaterialRequest $request): MaterialResource | JsonResponse
    {
        try {
            $material = $this->materialService->createMaterial($request->validated(), $request);
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@store BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@store Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_create'), 500);
        }
    }

    public function show(Request $request, string $id): MaterialResource | JsonResponse
    {
        try {
            $material = $this->materialService->findMaterialById((int)$id, $request);
            if (!$material) {
                return AdminResponse::error(trans_message('materials.not_found'), 404);
            }
            
            // Включаем нормы списания, если запрошено
            if ($request->has('include_consumption_rates')) {
                $material->setAttribute('include_consumption_rates', true);
            }
            
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@show BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_get'), 500);
        }
    }

    public function update(UpdateMaterialRequest $request, string $id): MaterialResource | JsonResponse
    {
        try {
            $updatedSuccessfully = $this->materialService->updateMaterial((int)$id, $request->validated(), $request);
            
            if (!$updatedSuccessfully) { 
                return AdminResponse::error(trans_message('materials.update_failed'), 404);
            }
            
            // После успешного обновления, снова получаем модель, чтобы вернуть актуальные данные
            $material = $this->materialService->findMaterialById((int)$id, $request);
            if (!$material) { // На всякий случай, если материал исчез между update и find
                return AdminResponse::error(trans_message('materials.not_found_after_update'), 404);
            }
            
            return new MaterialResource($material->load('measurementUnit'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@update BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_update'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->materialService->deleteMaterial((int)$id, $request);
            if (!$success) {
                return AdminResponse::error(trans_message('materials.delete_failed'), 404);
            }
            return AdminResponse::success(null, trans_message('materials.deleted'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@destroy BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_delete'), 500);
        }
    }

    /**
     * Автокомплит для материалов (для "умного прихода")
     */
    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $search = $request->query('q', '');
            $limit = min((int)$request->query('limit', 20), 50);
            $organizationId = $this->getOrganizationId($request);
            
            if (!$organizationId) {
                return AdminResponse::error(trans_message('materials.organization_not_found'), 400);
            }
            
            $materials = Material::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where(function($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                          ->orWhere('code', 'ilike', "%{$search}%");
                })
                ->with('measurementUnit:id,name,short_name')
                ->select([
                    'id',
                    'name',
                    'code',
                    'category',
                    'measurement_unit_id',
                    'default_price',
                    'additional_properties',
                ])
                ->limit($limit)
                ->orderBy('name')
                ->get();
            
            $data = $materials->map(function($material) {
                return [
                    'id' => $material->id,
                    'name' => $material->name,
                    'code' => $material->code,
                    'category' => $material->category,
                    'asset_type' => $material->additional_properties['asset_type'] ?? 'material',
                    'measurement_unit_id' => $material->measurement_unit_id,
                    'measurement_unit' => $material->measurementUnit ? [
                        'id' => $material->measurementUnit->id,
                        'name' => $material->measurementUnit->name,
                        'short_name' => $material->measurementUnit->short_name,
                    ] : null,
                    'default_price' => (float)$material->default_price,
                ];
            });
            
            return AdminResponse::success($data, trans_message('materials.autocomplete_success'));
        } catch (\Throwable $e) {
            Log::error('MaterialController@autocomplete Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.internal_error_autocomplete'), 500);
        }
    }

    public function getMeasurementUnits(Request $request): JsonResponse
    {
        try {
            $units = $this->materialService->getMeasurementUnits($request);
            if (is_array($units) && isset($units['success']) && $units['success'] === false) {
                return AdminResponse::error($units['message'] ?? trans_message('materials.measurement_units_error'), $units['code'] ?? 400);
            }
            return AdminResponse::success($units, trans_message('materials.measurement_units_retrieved'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@getMeasurementUnits BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@getMeasurementUnits Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.measurement_units_error'), 500);
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

            // Преобразуем результат сервиса в AdminResponse
            if (isset($result['success']) && $result['success']) {
                return AdminResponse::success($result['data'] ?? $result, $result['message'] ?? trans_message('materials.import_success'));
            } else {
                return AdminResponse::error($result['message'] ?? trans_message('materials.import_error'), $result['code'] ?? 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('MaterialController@importMaterials ValidationException', [
                'errors' => $e->errors(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.import_validation_error'), 422, $e->errors());
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@importMaterials BusinessLogicException', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@importMaterials Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.import_error'), 500);
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
                return AdminResponse::error(trans_message('materials.not_found'), 404);
            }

            $rates = $material->getConsumptionRatesWithWorkTypes();
            
            $data = [
                'material_id' => $material->id,
                'material_name' => $material->name,
                'consumption_rates' => $rates
            ];
            
            return AdminResponse::success($data, trans_message('materials.consumption_rates_retrieved'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@getConsumptionRates BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@getConsumptionRates Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.consumption_rates_error'), 500);
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
                'consumption_rates.required' => trans_message('materials.consumption_rates_required'),
                'consumption_rates.array' => trans_message('materials.consumption_rates_must_be_array'),
                'consumption_rates.*.numeric' => trans_message('materials.consumption_rates_must_be_numeric'),
                'consumption_rates.*.min' => trans_message('materials.consumption_rates_must_be_positive'),
            ]);

            $material = $this->materialService->findMaterialById($id, $request);
            if (!$material) {
                return AdminResponse::error(trans_message('materials.not_found'), 404);
            }

            // Проверяем существование видов работ
            $workTypeIds = array_keys($validated['consumption_rates']);
            $existingWorkTypes = WorkType::whereIn('id', $workTypeIds)->get();
            
            if (count($workTypeIds) != $existingWorkTypes->count()) {
                return AdminResponse::error(trans_message('materials.work_types_not_found'), 422);
            }

            // Получаем organization_id из middleware или материала
            $organizationId = $this->getOrganizationId($request) ?? $material->organization_id;

            // Обновляем связи в таблице work_type_materials
            foreach ($validated['consumption_rates'] as $workTypeId => $rate) {
                // Сначала пытаемся найти существующую запись
                $existingPivot = $material->workTypes()
                    ->wherePivot('work_type_id', $workTypeId)
                    ->wherePivot('organization_id', $organizationId)
                    ->first();
                
                if ($existingPivot) {
                    // Обновляем существующую запись
                    $material->workTypes()->updateExistingPivot($workTypeId, [
                        'default_quantity' => $rate,
                        'notes' => null,
                    ]);
                } else {
                    // Создаем новую запись
                    $material->workTypes()->attach($workTypeId, [
                        'organization_id' => $organizationId,
                        'default_quantity' => $rate,
                        'notes' => null,
                    ]);
                }
            }

            // Дополнительно сохраняем в JSON поле для обратной совместимости
            $material->consumption_rates = $validated['consumption_rates'];
            $material->save();

            // Возвращаем обновленные данные
            $data = [
                'material_id' => $material->id,
                'material_name' => $material->name,
                'consumption_rates' => $material->getConsumptionRatesWithWorkTypes()
            ];
            
            return AdminResponse::success($data, trans_message('materials.consumption_rates_updated'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('MaterialController@updateConsumptionRates ValidationException', [
                'id' => $id,
                'errors' => $e->errors(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.consumption_rates_validation_error'), 422, $e->errors());
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@updateConsumptionRates BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@updateConsumptionRates Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.consumption_rates_update_error'), 500);
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
                return AdminResponse::error(trans_message('materials.not_found'), 404);
            }

            // Проверка на наличие необходимых полей для интеграции
            $validationErrors = [];
            
            if (empty($material->external_code)) {
                $validationErrors[] = trans_message('materials.accounting_external_code_missing');
            }
            
            if (empty($material->sbis_nomenclature_code)) {
                $validationErrors[] = trans_message('materials.accounting_sbis_code_missing');
            }
            
            // Проверка соответствия единиц измерения
            if (empty($material->sbis_unit_code)) {
                $validationErrors[] = trans_message('materials.accounting_sbis_unit_missing');
            }
            
            // Проверка счета учета
            if (empty($material->accounting_account)) {
                $validationErrors[] = trans_message('materials.accounting_account_missing');
            }

            $data = [
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
            ];
            
            return AdminResponse::success($data, trans_message('materials.accounting_validation_success'));
        } catch (BusinessLogicException $e) {
            Log::error('MaterialController@validateForAccounting BusinessLogicException', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('MaterialController@validateForAccounting Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('materials.accounting_validation_error'), 500);
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