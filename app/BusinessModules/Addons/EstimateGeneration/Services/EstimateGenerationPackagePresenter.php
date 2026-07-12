<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use Illuminate\Support\Collection;

class EstimateGenerationPackagePresenter
{
    /**
     * @param  Collection<int, EstimateGenerationPackage>  $packages
     * @return array<string, mixed>
     */
    public function collection(Collection $packages): array
    {
        return [
            'packages' => $packages->map(fn (EstimateGenerationPackage $package): array => $this->summary($package))->values()->all(),
            'summary' => $this->summaryCounters($packages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(EstimateGenerationPackage $package): array
    {
        $rawTotals = $package->totals ?? ['total_cost' => 0, 'items_count' => 0];
        $hiddenServiceItemsCount = $this->hiddenServiceItemsCount($rawTotals);
        $visibleItemsCount = $this->visibleItemsCount($package, $rawTotals, $hiddenServiceItemsCount);
        $quantityReviewItemsCount = $this->quantityReviewItemsCount($rawTotals);
        $pricedItemsCount = $this->pricedItemsCount($rawTotals, $visibleItemsCount, $quantityReviewItemsCount);
        $totals = [
            ...$rawTotals,
            'items_count' => $visibleItemsCount,
            'total_items_count' => $visibleItemsCount,
            'priced_items_count' => $pricedItemsCount,
            'quantity_review_items_count' => $quantityReviewItemsCount,
            'operation_items_count' => 0,
            'review_notes_count' => 0,
            'hidden_service_items_count' => $hiddenServiceItemsCount,
        ];

        return [
            'id' => $package->id,
            'key' => $package->key,
            'title' => $package->title,
            'scope_type' => $package->scope_type,
            'status' => $package->status,
            'generation_stage' => $package->generation_stage,
            'generation_progress' => $package->generation_progress,
            'target_items_min' => $package->target_items_min,
            'target_items_max' => $package->target_items_max,
            'actual_items_count' => $visibleItemsCount,
            'totals' => $totals,
            'items_breakdown' => [
                'total' => $visibleItemsCount,
                'priced' => $pricedItemsCount,
                'quantity_reviews' => $quantityReviewItemsCount,
                'operations' => 0,
                'review_notes' => 0,
                'hidden_service_items' => $hiddenServiceItemsCount,
            ],
            'quality_summary' => $package->quality_summary ?? [
                'level' => 'planned',
                'critical_flags' => [],
                'warning_flags' => [],
            ],
            'sort_order' => $package->sort_order,
            'approved_at' => $package->approved_at?->toISOString(),
            'updated_at' => $package->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(EstimateGenerationPackage $package, Collection $items): array
    {
        $currentItems = $items
            ->sortByDesc(fn (EstimateGenerationPackageItem $item): string => sprintf('%020d:%020d', (int) ($item->revision ?? 0), (int) $item->id))
            ->unique(fn (EstimateGenerationPackageItem $item): string => (string) ($item->logical_key ?? $item->key));
        $visibleItems = $currentItems->filter(fn (EstimateGenerationPackageItem $item): bool => $this->isVisibleItem($item))->values();
        $pricedItemsCount = $visibleItems->filter(fn (EstimateGenerationPackageItem $item): bool => $this->isPricedItem($item))->count();
        $quantityReviewItemsCount = $visibleItems->filter(fn (EstimateGenerationPackageItem $item): bool => $this->isQuantityReviewItem($item))->count();
        $hiddenServiceItemsCount = $currentItems->count() - $visibleItems->count();

        return [
            'package' => $this->summary($package),
            'items' => $visibleItems->map(fn (EstimateGenerationPackageItem $item): array => $this->item($item))->values()->all(),
            'meta' => [
                'items_count' => $visibleItems->count(),
                'priced_items_count' => $pricedItemsCount,
                'quantity_review_items_count' => $quantityReviewItemsCount,
                'operation_items_count' => 0,
                'hidden_service_items_count' => $hiddenServiceItemsCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(EstimateGenerationPackageItem $item): array
    {
        $metadata = $item->metadata ?? [];
        $workComposition = is_array($metadata['work_composition'] ?? null)
            ? array_values($metadata['work_composition'])
            : [];
        $flags = $item->flags ?? [];
        $pricingStatus = $metadata['pricing_status'] ?? $this->pricingStatus($item);
        $pricingBlocker = $metadata['pricing_blocker'] ?? null;

        if (
            $item->item_type === 'priced_work'
            && (float) ($item->total_cost ?? 0) <= 0
            && in_array((string) $pricingStatus, ['calculated', 'calculated_review_required'], true)
        ) {
            $pricingStatus = 'not_calculated';
            $pricingBlocker ??= 'pricing_not_calculated';
        }

        return [
            'id' => $item->id,
            'key' => $item->logical_key ?? $item->key,
            'physical_key' => $item->key,
            'revision' => (int) ($item->revision ?? 1),
            'supersedes_item_id' => $item->supersedes_item_id,
            'parent_key' => $item->parent_key,
            'level' => $item->level,
            'item_type' => $item->item_type,
            'name' => $item->name,
            'work_category' => $metadata['work_category'] ?? null,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'quantity_basis' => $item->quantity_basis ?? [],
            'price_source' => $item->price_source,
            'pricing_status' => $pricingStatus,
            'pricing_blocker' => $pricingBlocker,
            'pricing_blocker_message' => $metadata['pricing_blocker_message'] ?? null,
            'normative_rate_code' => $metadata['normative_match']['code'] ?? null,
            'normative_match' => is_array($metadata['normative_match'] ?? null) ? $metadata['normative_match'] : null,
            'normative_candidates' => is_array($metadata['normative_candidates'] ?? null)
                ? array_values($metadata['normative_candidates'])
                : [],
            'normative_status' => $item->normative_status,
            'normative_confidence' => $item->normative_confidence,
            'unit_price' => $item->unit_price,
            'direct_cost' => $item->direct_cost,
            'overhead_cost' => $item->overhead_cost,
            'profit_cost' => $item->profit_cost,
            'total_cost' => $item->total_cost,
            'resources' => $item->resources ?? [],
            'work_composition' => $workComposition,
            'source_refs' => is_array($metadata['source_refs'] ?? null) ? array_values($metadata['source_refs']) : [],
            'confidence' => isset($metadata['confidence']) ? (float) $metadata['confidence'] : ($item->normative_confidence ?? 0.7),
            'validation_flags' => $flags,
            'flags' => $flags,
            'metadata' => $metadata,
            'sort_order' => $item->sort_order,
        ];
    }

    /**
     * @param  Collection<int, EstimateGenerationPackage>  $packages
     * @return array<string, int>
     */
    private function summaryCounters(Collection $packages): array
    {
        $priced = 0;
        $quantityReviews = 0;
        $hiddenServiceItems = 0;

        foreach ($packages as $package) {
            $totals = $package->totals ?? [];
            $hidden = $this->hiddenServiceItemsCount($totals);
            $visible = $this->visibleItemsCount($package, $totals, $hidden);
            $quantityReviewItems = $this->quantityReviewItemsCount($totals);
            $priced += $this->pricedItemsCount($totals, $visible, $quantityReviewItems);
            $quantityReviews += $quantityReviewItems;
            $hiddenServiceItems += $hidden;
        }

        return [
            'total' => $packages->count(),
            'planned' => $packages->where('status', 'planned')->count(),
            'processing' => $packages->filter(static fn (EstimateGenerationPackage $package): bool => in_array($package->status, ['queued', 'processing', 'generating'], true))->count(),
            'ready' => $packages->whereIn('status', ['ready_for_review', 'approved'])->count(),
            'review_required' => $packages->where('status', 'review_required')->count(),
            'approved' => $packages->where('status', 'approved')->count(),
            'blocked' => $packages->where('status', 'blocked')->count(),
            'failed' => $packages->where('status', 'failed')->count(),
            'priced_items_count' => $priced,
            'quantity_review_items_count' => $quantityReviews,
            'operation_items_count' => 0,
            'hidden_service_items_count' => $hiddenServiceItems,
        ];
    }

    private function isVisibleItem(EstimateGenerationPackageItem $item): bool
    {
        return ! in_array($item->item_type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true);
    }

    private function isPricedItem(EstimateGenerationPackageItem $item): bool
    {
        return $this->isVisibleItem($item) && ! $this->isQuantityReviewItem($item);
    }

    private function isQuantityReviewItem(EstimateGenerationPackageItem $item): bool
    {
        return $item->item_type === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE;
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function hiddenServiceItemsCount(array $totals): int
    {
        return (int) ($totals['operation_items_count'] ?? 0) + (int) ($totals['review_notes_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function quantityReviewItemsCount(array $totals): int
    {
        return max((int) ($totals['quantity_review_items_count'] ?? 0), 0);
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function visibleItemsCount(EstimateGenerationPackage $package, array $totals, int $hiddenServiceItemsCount): int
    {
        $rawTotal = (int) ($totals['total_items_count'] ?? $totals['items_count'] ?? $package->actual_items_count);

        return max($rawTotal - $hiddenServiceItemsCount, 0);
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function pricedItemsCount(
        array $totals,
        int $visibleItemsCount,
        int $quantityReviewItemsCount
    ): int {
        if (isset($totals['priced_items_count'])) {
            return max((int) $totals['priced_items_count'], 0);
        }

        return max($visibleItemsCount - $quantityReviewItemsCount, 0);
    }

    private function pricingStatus(EstimateGenerationPackageItem $item): string
    {
        if (! $this->isPricedItem($item)) {
            return 'not_applicable';
        }

        return (float) $item->total_cost > 0 ? 'calculated' : 'not_calculated';
    }
}
