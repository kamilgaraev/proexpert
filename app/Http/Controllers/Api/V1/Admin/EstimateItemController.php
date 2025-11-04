<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateItemResource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateItemController extends Controller
{
    public function __construct(
        protected EstimateItemService $itemService
    ) {}

    public function index(Request $request, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        $items = $estimateModel->items()
            ->with(['workType', 'measurementUnit', 'section'])
            ->paginate($request->input('per_page', 50));
        
        return response()->json([
            'data' => EstimateItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ]
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
            'estimate_section_id' => 'nullable|exists:estimate_sections,id',
            'position_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'work_type_id' => 'nullable|exists:work_types,id',
            'measurement_unit_id' => 'nullable|exists:measurement_units,id',
            'quantity' => 'required|numeric|min:0',
            'unit_price' => 'required|numeric|min:0',
            'justification' => 'nullable|string',
            'is_manual' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);
        
        $validated['estimate_id'] = $estimateModel->id;
        
        $item = $this->itemService->addItem($validated, $estimateModel);
        
        return response()->json([
            'data' => new EstimateItemResource($item),
            'message' => 'Позиция успешно добавлена'
        ], 201);
    }

    public function bulkStore(Request $request, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.estimate_section_id' => 'nullable|exists:estimate_sections,id',
            'items.*.name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.work_type_id' => 'nullable|exists:work_types,id',
            'items.*.measurement_unit_id' => 'nullable|exists:measurement_units,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.justification' => 'nullable|string',
            'items.*.is_manual' => 'nullable|boolean',
        ]);
        
        $items = $this->itemService->bulkAdd($validated['items'], $estimateModel);
        
        return response()->json([
            'data' => EstimateItemResource::collection($items),
            'message' => 'Позиции успешно добавлены'
        ], 201);
    }

    public function show(EstimateItem $item): JsonResponse
    {
        $this->authorize('view', $item->estimate);
        
        return response()->json([
            'data' => new EstimateItemResource($item->load(['workType', 'measurementUnit', 'resources']))
        ]);
    }

    public function update(Request $request, EstimateItem $item): JsonResponse
    {
        $this->authorize('update', $item->estimate);
        
        $validated = $request->validate([
            'estimate_section_id' => 'sometimes|nullable|exists:estimate_sections,id',
            'position_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'work_type_id' => 'sometimes|nullable|exists:work_types,id',
            'measurement_unit_id' => 'sometimes|nullable|exists:measurement_units,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_price' => 'sometimes|numeric|min:0',
            'justification' => 'nullable|string',
            'is_manual' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);
        
        $item = $this->itemService->updateItem($item, $validated);
        
        return response()->json([
            'data' => new EstimateItemResource($item),
            'message' => 'Позиция успешно обновлена'
        ]);
    }

    public function destroy(EstimateItem $item): JsonResponse
    {
        $this->authorize('update', $item->estimate);
        
        $this->itemService->deleteItem($item);
        
        return response()->json([
            'message' => 'Позиция успешно удалена'
        ]);
    }

    public function move(Request $request, EstimateItem $item): JsonResponse
    {
        $this->authorize('update', $item->estimate);
        
        $validated = $request->validate([
            'section_id' => 'required|exists:estimate_sections,id',
        ]);
        
        $item = $this->itemService->moveToSection($item, $validated['section_id']);
        
        return response()->json([
            'data' => new EstimateItemResource($item),
            'message' => 'Позиция успешно перемещена'
        ]);
    }
}

