<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersionService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateVersionController extends Controller
{
    public function __construct(
        protected EstimateVersionService $versionService
    ) {}

    public function index(Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        $history = $this->versionService->getVersionHistory($estimate);
        
        return response()->json([
            'data' => $history
        ]);
    }

    public function store(Request $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);
        
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);
        
        $newVersion = $this->versionService->createVersion(
            $estimate,
            $validated['description'] ?? null
        );
        
        return response()->json([
            'data' => new EstimateResource($newVersion),
            'message' => 'Новая версия сметы создана'
        ], 201);
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version1_id' => 'required|exists:estimates,id',
            'version2_id' => 'required|exists:estimates,id',
        ]);
        
        $version1 = Estimate::findOrFail($validated['version1_id']);
        $version2 = Estimate::findOrFail($validated['version2_id']);
        
        $this->authorize('view', $version1);
        $this->authorize('view', $version2);
        
        $comparison = $this->versionService->compareVersions($version1, $version2);
        
        return response()->json([
            'data' => $comparison
        ]);
    }

    public function rollback(Estimate $version): JsonResponse
    {
        $this->authorize('update', $version);
        
        $newVersion = $this->versionService->rollback($version);
        
        return response()->json([
            'data' => new EstimateResource($newVersion),
            'message' => 'Выполнен откат к версии сметы'
        ], 201);
    }
}

