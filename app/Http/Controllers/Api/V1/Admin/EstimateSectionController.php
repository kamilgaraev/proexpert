<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateSectionResource;
use App\Models\Estimate;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateSectionController extends Controller
{
    public function __construct(
        protected EstimateSectionService $sectionService
    ) {}

    public function index(Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        $sections = $estimate->sections()
            ->with(['children', 'items'])
            ->whereNull('parent_section_id')
            ->get();
        
        return response()->json([
            'data' => EstimateSectionResource::collection($sections)
        ]);
    }

    public function store(Request $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'section_number' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_summary' => 'nullable|boolean',
        ]);
        
        $validated['estimate_id'] = $estimate->id;
        
        $section = $this->sectionService->createSection($validated);
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно создан'
        ], 201);
    }

    public function show(EstimateSection $section): JsonResponse
    {
        $this->authorize('view', $section->estimate);
        
        return response()->json([
            'data' => new EstimateSectionResource($section->load(['children', 'items']))
        ]);
    }

    public function update(Request $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'section_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'sometimes|integer',
            'is_summary' => 'sometimes|boolean',
        ]);
        
        $section = $this->sectionService->updateSection($section, $validated);
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно обновлен'
        ]);
    }

    public function destroy(Request $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $cascade = $request->boolean('cascade', false);
        
        $this->sectionService->deleteSection($section, $cascade);
        
        return response()->json([
            'message' => 'Раздел успешно удален'
        ]);
    }

    public function move(Request $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'sort_order' => 'nullable|integer',
        ]);
        
        $section = $this->sectionService->moveSection(
            $section,
            $validated['parent_section_id'] ?? null,
            $validated['sort_order'] ?? null
        );
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно перемещен'
        ]);
    }
}

