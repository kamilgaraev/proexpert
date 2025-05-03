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

class MaterialController extends Controller
{
    protected MaterialService $materialService;

    public function __construct(MaterialService $materialService)
    {
        $this->materialService = $materialService;
        // TODO: Добавить middleware для проверки прав ('can:manage_materials')
    }

    public function index(Request $request): MaterialCollection
    {
        // TODO: Пагинация, фильтрация, API Resource
        $materials = $this->materialService->getActiveMaterialsForCurrentOrg();
        // return new MaterialCollection($materials); 
        // Временный обход, пока сервис не возвращает пагинацию
        return new MaterialCollection(MaterialResource::collection($materials));
    }

    public function store(StoreMaterialRequest $request): MaterialResource
    {
        $material = $this->materialService->createMaterial($request->validated());
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function show(string $id): MaterialResource | JsonResponse
    {
        $material = $this->materialService->findMaterialById((int)$id);
        if (!$material) {
            return response()->json(['message' => 'Material not found'], 404);
        }
        // TODO: Проверка принадлежности организации (в сервисе)
        // TODO: API Resource
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function update(UpdateMaterialRequest $request, string $id): MaterialResource | JsonResponse
    {
        $success = $this->materialService->updateMaterial((int)$id, $request->validated());
        if (!$success) {
            return response()->json(['message' => 'Material not found or update failed'], 404);
        }
        // TODO: API Resource
        $material = $this->materialService->findMaterialById((int)$id);
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function destroy(string $id): JsonResponse
    {
        $success = $this->materialService->deleteMaterial((int)$id);
        if (!$success) {
            // TODO: Уточнить обработку ошибки (может нельзя удалить из-за связей)
            return response()->json(['message' => 'Material not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }

    public function getMaterialBalances(int $id, Request $request): JsonResponse
    {
        $balances = $this->materialService->getMaterialBalancesByMaterial(
            $id,
            $request->get('per_page', 15),
            $request->get('project_id'),
            $request->get('sort_by', 'created_at'),
            $request->get('sort_direction', 'desc')
        );

        return response()->json($balances);
    }

    public function getMeasurementUnits(): JsonResponse
    {
        $units = $this->materialService->getMeasurementUnits();

        return response()->json($units);
    }

    public function importMaterials(Request $request): JsonResponse
    {
        $this->validate($request, [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
        ]);

        $result = $this->materialService->importMaterialsFromFile($request->file('file'));

        return response()->json($result);
    }
} 