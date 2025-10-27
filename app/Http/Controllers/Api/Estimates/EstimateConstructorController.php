<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EnhancedCalculationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EstimateConstructorController extends Controller
{
    public function __construct(
        protected EnhancedCalculationService $calculationService
    ) {}

    public function addItemsFromNormatives(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.normative_rate_id' => 'required|exists:normative_rates,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.section_id' => 'nullable|exists:estimate_sections,id',
            'apply_indices' => 'boolean',
            'calculation_date' => 'nullable|date',
            'coefficients' => 'nullable|array',
        ]);

        $estimate = Estimate::findOrFail($estimateId);
        $addedItems = [];

        DB::transaction(function () use ($request, $estimate, &$addedItems) {
            foreach ($request->input('items') as $itemData) {
                $rate = \App\Models\NormativeRate::with('resources')->findOrFail($itemData['normative_rate_id']);

                $item = new EstimateItem([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $itemData['section_id'] ?? null,
                    'item_type' => 'work',
                    'position_number' => $this->getNextPositionNumber($estimate->id, $itemData['section_id'] ?? null),
                ]);

                $options = [
                    'apply_indices' => $request->input('apply_indices', false),
                    'calculation_date' => $request->input('calculation_date') ? \Carbon\Carbon::parse($request->input('calculation_date')) : now(),
                    'coefficients' => $request->input('coefficients', []),
                ];

                $item = $this->calculationService->calculateItemFromNormativeRate(
                    $item,
                    $rate,
                    $itemData['quantity'],
                    $options
                );

                $item->save();
                $addedItems[] = $item->fresh(['normativeRate', 'section']);
            }
        });

        return response()->json([
            'message' => 'Позиции добавлены успешно',
            'added_count' => count($addedItems),
            'items' => $addedItems,
        ], 201);
    }

    public function bulkUpdate(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:estimate_items,id',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.section_id' => 'nullable|exists:estimate_sections,id',
            'items.*.position_number' => 'nullable|string',
        ]);

        $estimate = Estimate::findOrFail($estimateId);
        $updated = [];

        DB::transaction(function () use ($request, $estimate, &$updated) {
            foreach ($request->input('items') as $itemData) {
                $item = EstimateItem::where('estimate_id', $estimate->id)
                    ->findOrFail($itemData['id']);

                if (isset($itemData['quantity'])) {
                    $item->quantity = $itemData['quantity'];
                    
                    if ($item->normativeRate) {
                        $this->calculationService->recalculateItem($item);
                    }
                }

                if (isset($itemData['section_id'])) {
                    $item->estimate_section_id = $itemData['section_id'];
                }

                if (isset($itemData['position_number'])) {
                    $item->position_number = $itemData['position_number'];
                }

                $item->save();
                $updated[] = $item->fresh();
            }
        });

        return response()->json([
            'message' => 'Позиции обновлены',
            'updated_count' => count($updated),
            'items' => $updated,
        ]);
    }

    public function bulkDelete(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
        ]);

        $estimate = Estimate::findOrFail($estimateId);

        $deleted = EstimateItem::where('estimate_id', $estimate->id)
            ->whereIn('id', $request->input('item_ids'))
            ->delete();

        return response()->json([
            'message' => 'Позиции удалены',
            'deleted_count' => $deleted,
        ]);
    }

    public function reorderItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:estimate_items,id',
            'items.*.position_number' => 'required|string',
            'items.*.section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        $estimate = Estimate::findOrFail($estimateId);

        DB::transaction(function () use ($request, $estimate) {
            foreach ($request->input('items') as $itemData) {
                EstimateItem::where('estimate_id', $estimate->id)
                    ->where('id', $itemData['id'])
                    ->update([
                        'position_number' => $itemData['position_number'],
                        'estimate_section_id' => $itemData['section_id'] ?? null,
                    ]);
            }
        });

        return response()->json([
            'message' => 'Порядок позиций обновлен',
        ]);
    }

    public function moveItemsToSection(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'section_id' => 'required|exists:estimate_sections,id',
        ]);

        $estimate = Estimate::findOrFail($estimateId);
        $section = EstimateSection::where('estimate_id', $estimate->id)
            ->findOrFail($request->input('section_id'));

        $updated = EstimateItem::where('estimate_id', $estimate->id)
            ->whereIn('id', $request->input('item_ids'))
            ->update(['estimate_section_id' => $section->id]);

        return response()->json([
            'message' => 'Позиции перемещены в раздел',
            'updated_count' => $updated,
        ]);
    }

    public function copyItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'target_estimate_id' => 'required|exists:estimates,id',
            'target_section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        $sourceEstimate = Estimate::findOrFail($estimateId);
        $targetEstimate = Estimate::findOrFail($request->input('target_estimate_id'));

        $copiedItems = [];

        DB::transaction(function () use ($request, $sourceEstimate, $targetEstimate, &$copiedItems) {
            $items = EstimateItem::where('estimate_id', $sourceEstimate->id)
                ->whereIn('id', $request->input('item_ids'))
                ->get();

            foreach ($items as $item) {
                $newItem = $item->replicate();
                $newItem->estimate_id = $targetEstimate->id;
                $newItem->estimate_section_id = $request->input('target_section_id');
                $newItem->position_number = $this->getNextPositionNumber($targetEstimate->id, $newItem->estimate_section_id);
                $newItem->save();

                $copiedItems[] = $newItem;
            }
        });

        return response()->json([
            'message' => 'Позиции скопированы',
            'copied_count' => count($copiedItems),
            'items' => $copiedItems,
        ], 201);
    }

    public function applyCoefficientsToItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'coefficients' => 'required|array|min:1',
            'coefficients.*.id' => 'required|exists:regional_coefficients,id',
        ]);

        $estimate = Estimate::findOrFail($estimateId);

        $updated = $this->calculationService->bulkApplyCoefficients(
            $request->input('item_ids'),
            $request->input('coefficients')
        );

        return response()->json([
            'message' => 'Коэффициенты применены',
            'updated_count' => $updated,
        ]);
    }

    public function applyIndicesToItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'calculation_date' => 'required|date',
        ]);

        $estimate = Estimate::findOrFail($estimateId);

        $updated = $this->calculationService->bulkApplyIndices(
            $request->input('item_ids'),
            \Carbon\Carbon::parse($request->input('calculation_date'))
        );

        return response()->json([
            'message' => 'Индексы применены',
            'updated_count' => $updated,
        ]);
    }

    public function recalculateEstimate(int $estimateId): JsonResponse
    {
        $estimate = Estimate::findOrFail($estimateId);

        $estimate = $this->calculationService->recalculateEstimate($estimate, [
            'apply_indices' => true,
            'calculation_date' => now(),
        ]);

        return response()->json([
            'message' => 'Смета пересчитана',
            'estimate' => $estimate,
        ]);
    }

    protected function getNextPositionNumber(int $estimateId, ?int $sectionId): string
    {
        $query = EstimateItem::where('estimate_id', $estimateId);

        if ($sectionId) {
            $query->where('estimate_section_id', $sectionId);
        }

        $maxPosition = $query->max('position_number');

        if (!$maxPosition) {
            return $sectionId ? '1.1' : '1';
        }

        $parts = explode('.', $maxPosition);
        $parts[count($parts) - 1] = (int)$parts[count($parts) - 1] + 1;

        return implode('.', $parts);
    }
}
