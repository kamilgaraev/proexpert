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
            'totals' => $package->totals ?? ['total_cost' => 0, 'items_count' => 0],
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
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(EstimateGenerationPackageItem $item): array
    {
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
            'normative_status' => $item->normative_status,
            'normative_confidence' => $item->normative_confidence,
            'unit_price' => $item->unit_price,
            'direct_cost' => $item->direct_cost,
            'overhead_cost' => $item->overhead_cost,
            'profit_cost' => $item->profit_cost,
            'total_cost' => $item->total_cost,
            'resources' => $item->resources ?? [],
            'flags' => $item->flags ?? [],
            'metadata' => $item->metadata ?? [],
            'sort_order' => $item->sort_order,
        ];
    }

    /**
     * @param Collection<int, EstimateGenerationPackage> $packages
     * @return array<string, int>
     */
    private function summaryCounters(Collection $packages): array
    {
        return [
            'total' => $packages->count(),
            'planned' => $packages->where('status', 'planned')->count(),
            'processing' => $packages->filter(static fn (EstimateGenerationPackage $package): bool => in_array($package->status, ['queued', 'processing', 'generating'], true))->count(),
            'ready' => $packages->whereIn('status', ['ready_for_review', 'approved'])->count(),
            'approved' => $packages->where('status', 'approved')->count(),
            'blocked' => $packages->where('status', 'blocked')->count(),
            'failed' => $packages->where('status', 'failed')->count(),
        ];
    }
}
