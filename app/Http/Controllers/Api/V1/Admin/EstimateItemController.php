<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemNumberingService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateItemResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateItemController extends Controller
{
    public function __construct(
        protected EstimateItemService $itemService,
        protected EstimateItemNumberingService $numberingService
    ) {
        Log::info('[EstimateItemController::__construct] Контроллер создан', [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
        ]);
    }

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
        
        return AdminResponse::success(
            EstimateItemResource::collection($items),
            null,
            Response::HTTP_OK,
            [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ]
        );
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
            'item_type' => 'required|in:' . implode(',', EstimatePositionItemType::values()),
            'position_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'work_type_id' => 'nullable|exists:work_types,id',
            'measurement_unit_id' => 'nullable|exists:measurement_units,id',
            'quantity' => 'required|numeric|min:0',
            'unit_price' => 'required|numeric|min:0',
            'labor_hours' => 'nullable|numeric|min:0',
            'machinery_hours' => 'nullable|numeric|min:0',
            'materials_cost' => 'nullable|numeric|min:0',
            'machinery_cost' => 'nullable|numeric|min:0',
            'labor_cost' => 'nullable|numeric|min:0',
            'equipment_cost' => 'nullable|numeric|min:0',
            'normative_rate_id' => 'nullable|exists:normative_rates,id',
            'overhead_amount' => 'nullable|numeric|min:0',
            'profit_amount' => 'nullable|numeric|min:0',
            'justification' => 'nullable|string',
            'is_manual' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);
        
        $validated['estimate_id'] = $estimateModel->id;
        
        $item = $this->itemService->addItem($validated, $estimateModel);
        
        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_added'),
            Response::HTTP_CREATED
        );
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
        
        return AdminResponse::success(
            EstimateItemResource::collection($items),
            trans_message('estimate.items_added'),
            Response::HTTP_CREATED
        );
    }

    public function show(EstimateItem $item): JsonResponse
    {
        // Убеждаемся, что связь estimate загружена
        if (!$item->relationLoaded('estimate')) {
            $item->load('estimate');
        }
        
        $this->authorize('view', $item->estimate);
        
        return AdminResponse::success(
            new EstimateItemResource($item->load(['workType', 'measurementUnit', 'resources']))
        );
    }

    public function update(Request $request, EstimateItem $item): JsonResponse
    {
        Log::info('[EstimateItemController::update] ===== НАЧАЛО МЕТОДА =====', [
            'timestamp' => now()->toIso8601String(),
            'request_id' => uniqid('req_', true),
        ]);
        
        Log::info('[EstimateItemController::update] Параметр $item', [
            'item_type' => gettype($item),
            'item_class' => is_object($item) ? get_class($item) : 'not_object',
            'item_is_scalar' => is_scalar($item),
            'item_value' => is_scalar($item) ? $item : 'object',
            'item_id' => is_object($item) && isset($item->id) ? $item->id : null,
            'item_estimate_id' => is_object($item) && isset($item->estimate_id) ? $item->estimate_id : null,
            'item_deleted_at' => is_object($item) && isset($item->deleted_at) ? $item->deleted_at : null,
            'item_exists' => is_object($item) && method_exists($item, 'exists') ? $item->exists : false,
        ]);
        
        // Если объект пустой (не загружен из БД), значит route binding не сработал правильно
        if (!($item instanceof EstimateItem) || !$item->exists || !$item->id) {
            Log::error('[EstimateItemController::update] Route binding не сработал правильно!', [
                'item_type' => gettype($item),
                'item_class' => is_object($item) ? get_class($item) : 'not_object',
                'item_exists' => is_object($item) && method_exists($item, 'exists') ? $item->exists : false,
                'item_id' => is_object($item) && isset($item->id) ? $item->id : null,
                'item_value' => is_scalar($item) ? $item : 'object',
                'route_params' => $request->route()?->parameters(),
            ]);
            
            // Пытаемся найти элемент вручную
            $itemId = is_scalar($item) ? (int)$item : ($request->route('item') ?? null);
            
            if (!$itemId) {
                Log::error('[EstimateItemController::update] Не удалось определить ID элемента', [
                    'route_params' => $request->route()?->parameters(),
                ]);
                abort(404, 'Позиция сметы не найдена');
            }
            
            Log::info('[EstimateItemController::update] Попытка найти элемент вручную', [
                'item_id' => $itemId,
            ]);
            
            $item = EstimateItem::withTrashed()
                ->where('id', (int)$itemId)
                ->first();
            
            if (!$item) {
                Log::error('[EstimateItemController::update] Элемент не найден вручную', [
                    'item_id' => $itemId,
                ]);
                abort(404, 'Позиция сметы не найдена');
            }
            
            Log::info('[EstimateItemController::update] Элемент найден вручную', [
                'item_id' => $item->id,
                'item_estimate_id' => $item->estimate_id,
            ]);
        }
        
        Log::info('[EstimateItemController::update] Начало метода', [
            'item_id' => $item->id,
            'item_estimate_id' => $item->estimate_id,
            'item_deleted_at' => $item->deleted_at,
            'item_loaded' => $item->exists,
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'request_data' => $request->all(),
        ]);
        
        // Перезагружаем связь estimate с учетом soft deletes
        $item->load(['estimate' => function ($query) {
            $query->withTrashed();
        }]);
        
        Log::info('[EstimateItemController::update] После загрузки estimate', [
            'item_id' => $item->id,
            'estimate_loaded' => $item->relationLoaded('estimate'),
            'estimate_exists' => $item->estimate !== null,
            'estimate_id' => $item->estimate?->id,
            'estimate_organization_id' => $item->estimate?->organization_id,
            'estimate_status' => $item->estimate?->status,
            'estimate_deleted_at' => $item->estimate?->deleted_at,
        ]);
        
        // Проверяем, что estimate существует
        if (!$item->estimate) {
            Log::error('[EstimateItemController::update] Estimate не найден', [
                'item_id' => $item->id,
                'item_estimate_id' => $item->estimate_id,
            ]);
            abort(404, 'Смета не найдена');
        }
        
        $user = $request->user();
        Log::info('[EstimateItemController::update] Перед authorize', [
            'item_id' => $item->id,
            'estimate_id' => $item->estimate->id,
            'user_id' => $user?->id,
            'user_current_organization_id' => $user?->current_organization_id,
            'estimate_organization_id' => $item->estimate->organization_id,
        ]);
        
        $this->authorize('update', $item->estimate);
        
        Log::info('[EstimateItemController::update] После authorize - успешно', [
            'item_id' => $item->id,
            'estimate_id' => $item->estimate->id,
        ]);
        
        $validated = $request->validate([
            'estimate_section_id' => 'sometimes|nullable|exists:estimate_sections,id',
            'item_type' => 'sometimes|in:' . implode(',', EstimatePositionItemType::values()),
            'position_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'work_type_id' => 'sometimes|nullable|exists:work_types,id',
            'measurement_unit_id' => 'sometimes|nullable|exists:measurement_units,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_price' => 'sometimes|numeric|min:0',
            'labor_hours' => 'sometimes|numeric|min:0',
            'machinery_hours' => 'sometimes|numeric|min:0',
            'materials_cost' => 'sometimes|numeric|min:0',
            'machinery_cost' => 'sometimes|numeric|min:0',
            'labor_cost' => 'sometimes|numeric|min:0',
            'equipment_cost' => 'sometimes|numeric|min:0',
            'normative_rate_id' => 'sometimes|nullable|exists:normative_rates,id',
            'overhead_amount' => 'sometimes|numeric|min:0',
            'profit_amount' => 'sometimes|numeric|min:0',
            'justification' => 'nullable|string',
            'is_manual' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);
        
        $item = $this->itemService->updateItem($item, $validated);
        
        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_updated')
        );
    }

    public function destroy(EstimateItem $item): JsonResponse
    {
        // Убеждаемся, что связь estimate загружена
        if (!$item->relationLoaded('estimate')) {
            $item->load('estimate');
        }
        
        $this->authorize('update', $item->estimate);
        
        $this->itemService->deleteItem($item);
        
        return AdminResponse::success(null, trans_message('estimate.item_deleted'));
    }

    public function move(Request $request, EstimateItem $item): JsonResponse
    {
        // Убеждаемся, что связь estimate загружена
        if (!$item->relationLoaded('estimate')) {
            $item->load('estimate');
        }
        
        $this->authorize('update', $item->estimate);
        
        $validated = $request->validate([
            'section_id' => 'required|exists:estimate_sections,id',
        ]);
        
        $item = $this->itemService->moveToSection($item, $validated['section_id']);
        
        return AdminResponse::success(
            new EstimateItemResource($item),
            trans_message('estimate.item_moved')
        );
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
                    return AdminResponse::error(
                        trans_message('estimate.item_not_belongs_to_estimate'),
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
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

            return AdminResponse::success(
                EstimateItemResource::collection($items),
                trans_message('estimate.items_reordered')
            );
        } catch (\Exception $e) {
            Log::error('estimate.items.reorder.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.items_reorder_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
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

            return AdminResponse::success(
                ['numbering_mode' => $numberingMode],
                trans_message('estimate.item_numbering_recalculated')
            );
        } catch (\Exception $e) {
            Log::error('estimate.items.recalculate_numbers.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.item_numbering_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}

