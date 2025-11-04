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

    public function index(Request $request, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        $sections = $estimateModel->sections()
            ->with([
                'children.children.children.children',
                'items',
                'children.items',
                'children.children.items',
                'children.children.children.items',
            ])
            ->whereNull('parent_section_id')
            ->orderBy('sort_order')
            ->get();
        
        return response()->json([
            'data' => EstimateSectionResource::collection($sections)
        ]);
    }

    public function store(Request $request, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'section_number' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_summary' => 'nullable|boolean',
        ]);
        
        $validated['estimate_id'] = $estimateModel->id;
        
        $section = $this->sectionService->createSection($validated);
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно создан'
        ], 201);
    }

    public function show(EstimateSection $section): JsonResponse
    {
        $this->authorize('view', $section->estimate);
        
        $section->load([
            'children.children.children.children',
            'items',
            'children.items',
            'children.children.items',
            'children.children.children.items',
        ]);
        
        return response()->json([
            'data' => new EstimateSectionResource($section)
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

