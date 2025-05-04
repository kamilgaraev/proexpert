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

class MaterialController extends Controller
{
    protected MaterialService $materialService;

    public function __construct(MaterialService $materialService)
    {
        $this->materialService = $materialService;
        $this->middleware('can:manage-catalogs')->except('getMeasurementUnits');
    }

    /**
     * Display a paginated list of materials with filtering and sorting.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', 15);
        $materials = $this->materialService->getMaterialsPaginated($request, (int)$perPage);
        return MaterialResource::collection($materials);
    }

    public function store(StoreMaterialRequest $request): MaterialResource
    {
        $material = $this->materialService->createMaterial($request->validated(), $request);
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function show(Request $request, string $id): MaterialResource | JsonResponse
    {
        $material = $this->materialService->findMaterialById((int)$id, $request);
        if (!$material) {
            return response()->json(['message' => 'Material not found'], 404);
        }
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function update(UpdateMaterialRequest $request, string $id): MaterialResource | JsonResponse
    {
        $success = $this->materialService->updateMaterial((int)$id, $request->validated(), $request);
        if (!$success) {
            return response()->json(['message' => 'Material not found or update failed'], 404);
        }
        $material = $this->materialService->findMaterialById((int)$id, $request);
        return new MaterialResource($material->load('measurementUnit'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $success = $this->materialService->deleteMaterial((int)$id, $request);
        if (!$success) {
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