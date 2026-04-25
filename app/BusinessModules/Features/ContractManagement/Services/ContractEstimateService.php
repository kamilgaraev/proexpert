<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Services;

use App\Models\Contract;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\ContractEstimateItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractEstimateService
{
    public function attachItems(Contract $contract, Estimate $estimate, array $itemIds): Collection
    {
        return DB::transaction(function () use ($contract, $estimate, $itemIds) {
            $allIds = $this->resolveWithChildren($estimate->id, $itemIds);

            $items = EstimateItem::whereIn('id', $allIds)
                ->where('estimate_id', $estimate->id)
                ->get()
                ->keyBy('id');

            $attached = collect();

            foreach ($allIds as $itemId) {
                $item = $items->get($itemId);
                if (!$item) {
                    continue;
                }

                $link = ContractEstimateItem::firstOrCreate(
                    [
                        'contract_id'      => $contract->id,
                        'estimate_item_id' => $item->id,
                    ],
                    [
                        'estimate_id' => $estimate->id,
                        'quantity'    => $item->quantity_total,
                        'amount'      => $this->calculateAmount($item),
                    ]
                );

                $attached->push($link);
            }

            Log::info('contract_estimate_items.attached', [
                'contract_id'  => $contract->id,
                'estimate_id'  => $estimate->id,
                'item_ids'     => $allIds,
                'count'        => $attached->count(),
            ]);

            return $attached;
        });
    }

    public function detachItems(Contract $contract, array $itemIds): void
    {
        $allIds = $this->resolveChildrenForDetach($contract->id, $itemIds);

        ContractEstimateItem::where('contract_id', $contract->id)
            ->whereIn('estimate_item_id', $allIds)
            ->delete();

        Log::info('contract_estimate_items.detached', [
            'contract_id' => $contract->id,
            'item_ids'    => $allIds,
        ]);
    }

    public function syncItems(Contract $contract, Estimate $estimate, array $itemIds): Collection
    {
        return DB::transaction(function () use ($contract, $estimate, $itemIds) {
            ContractEstimateItem::where('contract_id', $contract->id)
                ->where('estimate_id', $estimate->id)
                ->delete();

            if (empty($itemIds)) {
                return collect();
            }

            return $this->attachItems($contract, $estimate, $itemIds);
        });
    }

    public function getItemsForContract(Contract $contract, ?int $estimateId = null): Collection
    {
        $query = ContractEstimateItem::with([
            'estimateItem',
            'estimateItem.section',
            'estimateItem.section.parent',
            'estimateItem.measurementUnit',
            'estimateItem.childItems',
        ])->where('contract_id', $contract->id);

        if ($estimateId !== null) {
            $query->where('estimate_id', $estimateId);
        }

        return $query->get();
    }

    public function getContractsByEstimateItem(EstimateItem $item): Collection
    {
        return $item->contracts()->get();
    }

    public function calculateContractEstimateTotal(Contract $contract, ?int $estimateId = null): float
    {
        $query = ContractEstimateItem::where('contract_id', $contract->id);

        if ($estimateId !== null) {
            $query->where('estimate_id', $estimateId);
        }

        return round((float) $query->sum('amount'), 2);
    }

    public function getSummary(Contract $contract): array
    {
        $links = ContractEstimateItem::where('contract_id', $contract->id)
            ->with('estimateItem')
            ->get();

        $byEstimate = $links->groupBy('estimate_id');

        $estimates = [];
        foreach ($byEstimate as $estimateId => $group) {
            $estimates[] = [
                'estimate_id'  => $estimateId,
                'items_count'  => $group->count(),
                'total_amount' => round($group->sum('amount'), 2),
            ];
        }

        return [
            'contract_id'          => $contract->id,
            'total_linked_items'   => $links->count(),
            'total_amount'         => round($links->sum('amount'), 2),
            'by_estimate'          => $estimates,
        ];
    }

    private function resolveWithChildren(int $estimateId, array $itemIds): array
    {
        $result = array_unique($itemIds);

        $parentItems = EstimateItem::whereIn('id', $itemIds)
            ->where('estimate_id', $estimateId)
            ->whereNotNull('parent_work_id')
            ->pluck('id')
            ->toArray();

        $childIds = EstimateItem::where('estimate_id', $estimateId)
            ->whereIn('parent_work_id', array_diff($itemIds, $parentItems))
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($result, $childIds));
    }

    private function resolveChildrenForDetach(int $contractId, array $itemIds): array
    {
        $childIds = EstimateItem::whereIn('parent_work_id', $itemIds)
            ->whereHas('contractLinks', function ($q) use ($contractId) {
                $q->where('contract_id', $contractId);
            })
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($itemIds, $childIds));
    }

    private function calculateAmount(EstimateItem $item): float
    {
        if ($item->total_amount !== null && (float) $item->total_amount > 0) {
            return round((float) $item->total_amount, 2);
        }

        $quantity = (float) ($item->quantity_total ?? $item->quantity ?? 0);
        $price    = (float) ($item->unit_price ?? 0);

        return round($quantity * $price, 2);
    }
}
