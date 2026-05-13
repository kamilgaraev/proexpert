<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EnhancedCalculationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimateSection;
use App\Models\NormativeRate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EstimateConstructorController extends Controller
{
    public function __construct(
        protected EnhancedCalculationService $calculationService
    ) {
    }

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

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $sectionIds = collect($request->input('items'))
            ->pluck('section_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (!$this->allSectionsBelongToEstimate($sectionIds, $estimate->id)) {
            return $this->notFound();
        }

        $addedItems = [];

        DB::transaction(function () use ($request, $estimate, &$addedItems): void {
            foreach ($request->input('items') as $itemData) {
                $sectionId = $itemData['section_id'] ?? null;
                $rate = NormativeRate::with('resources')->findOrFail($itemData['normative_rate_id']);

                $item = new EstimateItem([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'item_type' => 'work',
                    'position_number' => $this->getNextPositionNumber($estimate->id, $sectionId !== null ? (int) $sectionId : null),
                ]);

                $options = [
                    'apply_indices' => $request->input('apply_indices', false),
                    'calculation_date' => $request->input('calculation_date') ? Carbon::parse($request->input('calculation_date')) : now(),
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

        return AdminResponse::success([
            'added_count' => count($addedItems),
            'items' => $addedItems,
        ], trans_message('estimate_constructor.items_added'), Response::HTTP_CREATED);
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

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = collect($request->input('items'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }
        $sectionIds = collect($request->input('items'))
            ->pluck('section_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (!$this->allSectionsBelongToEstimate($sectionIds, $estimate->id)) {
            return $this->notFound();
        }

        $updated = [];

        DB::transaction(function () use ($request, $estimate, &$updated): void {
            foreach ($request->input('items') as $itemData) {
                $sectionId = $itemData['section_id'] ?? null;
                $item = EstimateItem::query()
                    ->where('estimate_id', $estimate->id)
                    ->findOrFail($itemData['id']);

                if (isset($itemData['quantity'])) {
                    $item->quantity = $itemData['quantity'];

                    if ($item->normativeRate) {
                        $this->calculationService->recalculateItem($item);
                    }
                }

                if (isset($itemData['section_id'])) {
                    $item->estimate_section_id = $sectionId;
                }

                if (isset($itemData['position_number'])) {
                    $item->position_number = $itemData['position_number'];
                }

                $item->save();
                $updated[] = $item->fresh();
            }
        });

        return AdminResponse::success([
            'updated_count' => count($updated),
            'items' => $updated,
        ], trans_message('estimate_constructor.items_updated'));
    }

    public function bulkDelete(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = array_map('intval', $request->input('item_ids'));
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }

        $deleted = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereIn('id', $itemIds)
            ->delete();

        return AdminResponse::success([
            'deleted_count' => $deleted,
        ], trans_message('estimate_constructor.items_deleted'));
    }

    public function reorderItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:estimate_items,id',
            'items.*.position_number' => 'required|string',
            'items.*.section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = collect($request->input('items'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }
        $sectionIds = collect($request->input('items'))
            ->pluck('section_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (!$this->allSectionsBelongToEstimate($sectionIds, $estimate->id)) {
            return $this->notFound();
        }

        DB::transaction(function () use ($request, $estimate): void {
            foreach ($request->input('items') as $itemData) {
                $sectionId = $itemData['section_id'] ?? null;
                EstimateItem::query()
                    ->where('estimate_id', $estimate->id)
                    ->where('id', $itemData['id'])
                    ->update([
                        'position_number' => $itemData['position_number'],
                        'estimate_section_id' => $sectionId,
                    ]);
            }
        });

        return AdminResponse::success([
            'reordered_count' => count($itemIds),
        ], trans_message('estimate_constructor.items_reordered'));
    }

    public function moveItemsToSection(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'section_id' => 'required|exists:estimate_sections,id',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = array_map('intval', $request->input('item_ids'));
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }

        $section = EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->find($request->input('section_id'));

        if (!$section) {
            return $this->notFound();
        }

        $updated = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereIn('id', $itemIds)
            ->update(['estimate_section_id' => $section->id]);

        return AdminResponse::success([
            'moved_count' => $updated,
            'updated_count' => $updated,
        ], trans_message('estimate_constructor.items_moved'));
    }

    public function copyItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'target_estimate_id' => 'required|exists:estimates,id',
            'target_section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        $sourceEstimate = $this->findEstimate($request, $estimateId);
        $targetEstimate = $this->findEstimate($request, (int) $request->input('target_estimate_id'));
        if (!$sourceEstimate || !$targetEstimate) {
            return $this->notFound();
        }

        $itemIds = array_map('intval', $request->input('item_ids'));
        if (!$this->allItemsBelongToEstimate($itemIds, $sourceEstimate->id)) {
            return $this->notFound();
        }

        $targetSectionId = $request->input('target_section_id');
        if ($targetSectionId !== null && !$this->sectionBelongsToEstimate((int) $targetSectionId, $targetEstimate->id)) {
            return $this->notFound();
        }

        $copiedItems = [];

        DB::transaction(function () use ($sourceEstimate, $targetEstimate, $targetSectionId, $itemIds, &$copiedItems): void {
            $items = EstimateItem::query()
                ->where('estimate_id', $sourceEstimate->id)
                ->whereIn('id', $itemIds)
                ->get();

            foreach ($items as $item) {
                $newItem = $item->replicate();
                $newItem->estimate_id = $targetEstimate->id;
                $newItem->estimate_section_id = $targetSectionId;
                $newItem->position_number = $this->getNextPositionNumber($targetEstimate->id, $targetSectionId !== null ? (int) $targetSectionId : null);
                $newItem->save();

                $copiedItems[] = $newItem;
            }
        });

        return AdminResponse::success([
            'copied_count' => count($copiedItems),
            'items' => $copiedItems,
        ], trans_message('estimate_constructor.items_copied'), Response::HTTP_CREATED);
    }

    public function applyCoefficientsToItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'coefficients' => 'required|array|min:1',
            'coefficients.*.id' => 'required|exists:regional_coefficients,id',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = array_map('intval', $request->input('item_ids'));
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }

        $updated = $this->calculationService->bulkApplyCoefficients($itemIds, $request->input('coefficients'));

        return AdminResponse::success([
            'updated_count' => $updated,
        ], trans_message('estimate_constructor.coefficients_applied'));
    }

    public function applyIndicesToItems(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|exists:estimate_items,id',
            'calculation_date' => 'required|date',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $itemIds = array_map('intval', $request->input('item_ids'));
        if (!$this->allItemsBelongToEstimate($itemIds, $estimate->id)) {
            return $this->notFound();
        }

        $updated = $this->calculationService->bulkApplyIndices($itemIds, Carbon::parse($request->input('calculation_date')));

        return AdminResponse::success([
            'updated_count' => $updated,
        ], trans_message('estimate_constructor.indices_applied'));
    }

    public function addItemsFromCatalog(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'required|exists:estimate_position_catalog,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }
        $sectionIds = collect($request->input('items'))
            ->pluck('section_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (!$this->allSectionsBelongToEstimate($sectionIds, $estimate->id)) {
            return $this->notFound();
        }

        $addedItems = [];

        DB::transaction(function () use ($request, $estimate, &$addedItems): void {
            foreach ($request->input('items') as $itemData) {
                $sectionId = $itemData['section_id'] ?? null;
                $catalogItem = EstimatePositionCatalog::query()
                    ->where('organization_id', $estimate->organization_id)
                    ->findOrFail($itemData['catalog_item_id']);

                $item = EstimateItem::create([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'catalog_item_id' => $catalogItem->id,
                    'item_type' => $catalogItem->item_type,
                    'position_number' => $this->getNextPositionNumber($estimate->id, $sectionId !== null ? (int) $sectionId : null),
                    'name' => $catalogItem->name,
                    'description' => $catalogItem->description,
                    'measurement_unit_id' => $catalogItem->measurement_unit_id,
                    'work_type_id' => $catalogItem->work_type_id,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $catalogItem->unit_price,
                    'direct_costs' => $catalogItem->direct_costs ?? ($catalogItem->unit_price * $itemData['quantity']),
                    'total_amount' => $catalogItem->unit_price * $itemData['quantity'],
                    'is_manual' => true,
                    'metadata' => [
                        'source' => 'catalog',
                        'catalog_item_id' => $catalogItem->id,
                    ],
                ]);

                $catalogItem->incrementUsage();

                $addedItems[] = $item->fresh(['measurementUnit', 'workType', 'catalogItem', 'section']);
            }
        });

        return AdminResponse::success([
            'added_count' => count($addedItems),
            'items' => $addedItems,
        ], trans_message('estimate_constructor.catalog_items_added'), Response::HTTP_CREATED);
    }

    public function recalculateEstimate(Request $request, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimate($request, $estimateId);
        if (!$estimate) {
            return $this->notFound();
        }

        $estimate = $this->calculationService->recalculateEstimate($estimate, [
            'apply_indices' => true,
            'calculation_date' => now(),
        ]);

        return AdminResponse::success([
            'estimate' => $estimate,
        ], trans_message('estimate_constructor.estimate_recalculated'));
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
        $parts[count($parts) - 1] = (int) $parts[count($parts) - 1] + 1;

        return implode('.', $parts);
    }

    private function findEstimate(Request $request, int $estimateId): ?Estimate
    {
        return Estimate::query()
            ->where('organization_id', (int) $request->user()->current_organization_id)
            ->find($estimateId);
    }

    private function sectionBelongsToEstimate(int $sectionId, int $estimateId): bool
    {
        return EstimateSection::query()
            ->where('estimate_id', $estimateId)
            ->where('id', $sectionId)
            ->exists();
    }

    private function allSectionsBelongToEstimate(array $sectionIds, int $estimateId): bool
    {
        if ($sectionIds === []) {
            return true;
        }

        return EstimateSection::query()
            ->where('estimate_id', $estimateId)
            ->whereIn('id', $sectionIds)
            ->count() === count(array_unique($sectionIds));
    }

    private function allItemsBelongToEstimate(array $itemIds, int $estimateId): bool
    {
        if ($itemIds === []) {
            return false;
        }

        return EstimateItem::query()
            ->where('estimate_id', $estimateId)
            ->whereIn('id', $itemIds)
            ->count() === count(array_unique($itemIds));
    }

    private function notFound(): JsonResponse
    {
        return AdminResponse::error(trans_message('estimate_constructor.not_found'), Response::HTTP_NOT_FOUND);
    }
}
