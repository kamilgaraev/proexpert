<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Queries;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class InventoryActRegistryQuery
{
    /**
     * @return array{
     *     acts: LengthAwarePaginator<int, InventoryAct>,
     *     metrics: array{acts_total: int, draft_acts: int, in_progress_acts: int, discrepancy_items: int}
     * }
     */
    public function get(
        int $organizationId,
        ?int $warehouseId,
        ?string $status,
        ?string $search,
        int $perPage,
    ): array {
        $query = $this->filteredQuery($organizationId, $warehouseId, $status, $search);

        return [
            'acts' => (clone $query)
                ->with(['warehouse', 'creator'])
                ->withCount('items')
                ->withCount([
                    'items as items_with_discrepancy_count' => fn (Builder $query) => $query
                        ->whereNotNull('difference')
                        ->whereRaw('ABS(difference) >= ?', [0.001]),
                ])
                ->withSum('items as items_total_difference_value', 'total_value')
                ->orderByDesc('inventory_date')
                ->paginate($perPage),
            'metrics' => $this->metrics($query),
        ];
    }

    /**
     * @return Builder<InventoryAct>
     */
    private function filteredQuery(
        int $organizationId,
        ?int $warehouseId,
        ?string $status,
        ?string $search,
    ): Builder {
        return InventoryAct::query()
            ->where('organization_id', $organizationId)
            ->search($search)
            ->when(
                $warehouseId !== null,
                fn (Builder $query) => $query->where('warehouse_id', $warehouseId)
            )
            ->when(
                $status !== null,
                fn (Builder $query) => $query->where('status', $status)
            );
    }

    /**
     * @param  Builder<InventoryAct>  $query
     * @return array{acts_total: int, draft_acts: int, in_progress_acts: int, discrepancy_items: int}
     */
    private function metrics(Builder $query): array
    {
        $statusMetrics = (clone $query)
            ->toBase()
            ->selectRaw('COUNT(*) AS acts_total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_acts', [InventoryAct::STATUS_DRAFT])
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS in_progress_acts',
                [InventoryAct::STATUS_IN_PROGRESS]
            )
            ->first();

        $discrepancyItems = InventoryActItem::query()
            ->whereIn('inventory_act_id', (clone $query)->select('inventory_acts.id'))
            ->whereNotNull('difference')
            ->whereRaw('ABS(difference) >= ?', [0.001])
            ->count();

        return [
            'acts_total' => (int) ($statusMetrics?->acts_total ?? 0),
            'draft_acts' => (int) ($statusMetrics?->draft_acts ?? 0),
            'in_progress_acts' => (int) ($statusMetrics?->in_progress_acts ?? 0),
            'discrepancy_items' => $discrepancyItems,
        ];
    }
}
