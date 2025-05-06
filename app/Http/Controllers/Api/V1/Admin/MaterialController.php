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

    public function getMeasurementUnits(): JsonResponse
    {
        try {
            $units = $this->materialService->getMeasurementUnits();
            return response()->json(['success' => true, 'data' => $units]);
        } catch (BusinessLogicException $e) { // Маловероятно для этого метода, но для консистентности
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in MaterialController@getMeasurementUnits', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении единиц измерения.'], 500);
        }
    }

    public function importMaterials(Request $request): JsonResponse
    {
        try {
            // Валидация файла остается здесь, т.к. это не FormRequest
            $validatedData = $this->validate($request, [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
            ]);

            $result = $this->materialService->importMaterialsFromFile($validatedData['file']);

            return response()->json($result); // Предполагаем, что сервис вернет массив с success/message
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
} 