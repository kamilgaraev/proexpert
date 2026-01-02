<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemNumberingService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateItemResource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EstimateItemController extends Controller
{
    public function __construct(
        protected EstimateItemService $itemService,
        protected EstimateItemNumberingService $numberingService
    ) {}

    public function index(Request $request, $project, int $estimate): JsonResponse
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

    public function store(Request $request, $project, int $estimate): JsonResponse
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
            'overhead_amount' => 'nullable|numeric|min:0',
            'profit_amount' => 'nullable|numeric|min:0',
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

    public function bulkStore(Request $request, $project, int $estimate): JsonResponse
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
            'items.*.overhead_amount' => 'nullable|numeric|min:0',
            'items.*.profit_amount' => 'nullable|numeric|min:0',
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
        Log::info('[EstimateItemController::update] Start', [
            'item_id' => $item->id,
            'estimate_id' => $item->estimate_id,
            'user_id' => $request->user()->id ?? null,
            'user_org' => $request->user()->current_organization_id ?? null,
            'estimate_org' => $item->estimate->organization_id ?? null,
            'estimate_status' => $item->estimate->status ?? null,
        ]);
        
        try {
            $this->authorize('update', $item->estimate);
            Log::info('[EstimateItemController::update] Authorization passed');
        } catch (\Exception $e) {
            Log::error('[EstimateItemController::update] Authorization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        $validated = $request->validate([
            'estimate_section_id' => 'sometimes|nullable|exists:estimate_sections,id',
            'position_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'work_type_id' => 'sometimes|nullable|exists:work_types,id',
            'measurement_unit_id' => 'sometimes|nullable|exists:measurement_units,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_price' => 'sometimes|numeric|min:0',
            'overhead_amount' => 'sometimes|numeric|min:0',
            'profit_amount' => 'sometimes|numeric|min:0',
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

    /**
     * Массовое обновление порядка позиций (для drag-and-drop)
     * 
     * @param Request $request
     * @param int $project ID проекта
     * @param int $estimate ID сметы
     * @return JsonResponse
     * 
     * Формат входных данных:
     * {
     *   "items": [
     *     {"id": 1, "estimate_section_id": 1, "sort_order": 0},
     *     {"id": 2, "estimate_section_id": 1, "sort_order": 1},
     *     {"id": 3, "estimate_section_id": 2, "sort_order": 0}
     *   ],
     *   "numbering_mode": "section"  // optional: global, section, hierarchical
     * }
     */
    public function reorder(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:estimate_items,id',
            'items.*.estimate_section_id' => 'nullable|exists:estimate_sections,id',
            'items.*.sort_order' => 'required|integer|min:0',
            'numbering_mode' => 'nullable|string|in:global,section,hierarchical',
        ]);

        $numberingMode = $validated['numbering_mode'] ?? EstimateItemNumberingService::NUMBERING_BY_SECTION;

        try {
            // Обновляем порядок и секции для всех позиций
            foreach ($validated['items'] as $itemData) {
                $item = EstimateItem::find($itemData['id']);
                
                // Проверяем, что позиция принадлежит данной смете
                if ($item->estimate_id !== $estimateModel->id) {
                    return response()->json([
                        'success' => false,
                        'error' => "Позиция {$itemData['id']} не принадлежит данной смете"
                    ], 422);
                }
                
                $item->update([
                    'estimate_section_id' => $itemData['estimate_section_id'] ?? null,
                    // sort_order будет использоваться для определения порядка при пересчете
                ]);
            }

            // Пересчитываем номера всех позиций после изменения порядка
            $this->numberingService->recalculateAllItemNumbers($estimateModel->id, $numberingMode);

            // Возвращаем обновленный список позиций
            $items = $estimateModel->items()
                ->with(['workType', 'measurementUnit', 'section'])
                ->orderBy('estimate_section_id')
                ->orderBy('position_number')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Порядок позиций успешно обновлен',
                'data' => EstimateItemResource::collection($items)
            ]);
        } catch (\Exception $e) {
            Log::error('estimate.items.reorder.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить порядок позиций'
            ], 500);
        }
    }

    /**
     * Пересчитать номера всех позиций сметы вручную
     * 
     * @param Request $request
     * @param int $project ID проекта
     * @param int $estimate ID сметы
     * @return JsonResponse
     */
    public function recalculateNumbers(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);

        $validated = $request->validate([
            'numbering_mode' => 'nullable|string|in:global,section,hierarchical',
        ]);

        $numberingMode = $validated['numbering_mode'] ?? EstimateItemNumberingService::NUMBERING_BY_SECTION;

        try {
            $this->numberingService->recalculateAllItemNumbers($estimateModel->id, $numberingMode);

            return response()->json([
                'success' => true,
                'message' => 'Нумерация позиций успешно пересчитана',
                'numbering_mode' => $numberingMode
            ]);
        } catch (\Exception $e) {
            Log::error('estimate.items.recalculate_numbers.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось пересчитать нумерацию'
            ], 500);
        }
    }
}

