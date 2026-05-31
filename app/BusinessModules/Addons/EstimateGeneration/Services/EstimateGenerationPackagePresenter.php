<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use Illuminate\Support\Collection;

class EstimateGenerationPackagePresenter
{
    /**
     * @param Collection<int, EstimateGenerationPackage> $packages
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
        $totals = $package->totals ?? ['total_cost' => 0, 'items_count' => 0];

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
            'actual_items_count' => $package->actual_items_count,
            'totals' => $totals,
            'items_breakdown' => [
                'total' => (int) ($totals['total_items_count'] ?? $totals['items_count'] ?? $package->actual_items_count),
                'priced' => (int) ($totals['priced_items_count'] ?? $package->actual_items_count),
                'operations' => (int) ($totals['operation_items_count'] ?? 0),
                'review_notes' => (int) ($totals['review_notes_count'] ?? 0),
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
        return [
            'package' => $this->summary($package),
            'items' => $items->map(fn (EstimateGenerationPackageItem $item): array => $this->item($item))->values()->all(),
            'meta' => [
                'items_count' => $items->count(),
                'priced_items_count' => $items->filter(fn (EstimateGenerationPackageItem $item): bool => $this->isPricedItem($item))->count(),
                'operation_items_count' => $items->filter(fn (EstimateGenerationPackageItem $item): bool => in_array($item->item_type, ['operation', 'resource_note'], true))->count(),
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

        return [
            'id' => $item->id,
            'key' => $item->key,
            'parent_key' => $item->parent_key,
            'level' => $item->level,
            'item_type' => $item->item_type,
            'name' => $item->name,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'quantity_basis' => $item->quantity_basis ?? [],
            'price_source' => $item->price_source,
            'pricing_status' => $metadata['pricing_status'] ?? $this->pricingStatus($item),
            'pricing_blocker' => $metadata['pricing_blocker'] ?? null,
            'pricing_blocker_message' => $metadata['pricing_blocker_message'] ?? null,
            'normative_status' => $item->normative_status,
            'normative_confidence' => $item->normative_confidence,
            'unit_price' => $item->unit_price,
            'direct_cost' => $item->direct_cost,
            'overhead_cost' => $item->overhead_cost,
            'profit_cost' => $item->profit_cost,
            'total_cost' => $item->total_cost,
            'resources' => $item->resources ?? [],
            'work_composition' => $workComposition,
            'flags' => $item->flags ?? [],
            'metadata' => $metadata,
            'sort_order' => $item->sort_order,
        ];
    }

    /**
     * @param Collection<int, EstimateGenerationPackage> $packages
     * @return array<string, int>
     */
    private function summaryCounters(Collection $packages): array
    {
        $priced = 0;
        $operations = 0;

        foreach ($packages as $package) {
            $totals = $package->totals ?? [];
            $priced += (int) ($totals['priced_items_count'] ?? 0);
            $operations += (int) ($totals['operation_items_count'] ?? 0);
        }

        return [
            'total' => $packages->count(),
            'planned' => $packages->where('status', 'planned')->count(),
            'processing' => $packages->filter(static fn (EstimateGenerationPackage $package): bool => in_array($package->status, ['queued', 'processing', 'generating'], true))->count(),
            'ready' => $packages->whereIn('status', ['ready_for_review', 'approved'])->count(),
            'approved' => $packages->where('status', 'approved')->count(),
            'blocked' => $packages->where('status', 'blocked')->count(),
            'failed' => $packages->where('status', 'failed')->count(),
            'priced_items_count' => $priced,
            'operation_items_count' => $operations,
        ];
    }

    private function isPricedItem(EstimateGenerationPackageItem $item): bool
    {
        return !in_array($item->item_type, ['operation', 'resource_note', 'review_note'], true);
    }

    private function pricingStatus(EstimateGenerationPackageItem $item): string
    {
        if (!$this->isPricedItem($item)) {
            return 'not_applicable';
        }

        return (float) $item->total_cost > 0 ? 'calculated' : 'not_calculated';
    }
}
