<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractEstimateService
{
    public function __construct(
        private readonly EstimateCacheService $estimateCacheService,
        private readonly \App\BusinessModules\Features\BudgetEstimates\Services\Finance\ContractEstimateFinanceAdapter $financeAdapter,
    ) {}

    public function attachItems(Contract $contract, Estimate $estimate, array $itemIds, bool $includeVat = false, ?\App\Models\User $actor = null, ?string $rate = null): Collection
    {
        if ($actor === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        if ((int) $contract->organization_id !== (int) $estimate->organization_id || (int) $contract->project_id !== (int) $estimate->project_id) {
            throw new DomainException('contract_estimate_items_invalid');
        }
        $allIds = $this->resolveWithChildren($estimate->id, $itemIds);
        $this->financeAdapter->attach($actor, $contract, $estimate, $allIds, $includeVat, $rate);

        return $this->getItemsForContract($contract, (int) $estimate->id)->whereIn('estimate_item_id', $allIds)->values();
    }

    public function detachItems(Contract $contract, array $itemIds, ?\App\Models\User $actor = null): void
    {
        if ($actor === null || (int) $actor->current_organization_id !== (int) $contract->organization_id) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        DB::transaction(function () use ($contract, $itemIds, $actor): void {
            $allIds = $this->resolveChildrenForDetach($contract->id, $itemIds);
            $estimateIds = ContractEstimateItem::where('contract_id', $contract->id)
                ->whereIn('estimate_item_id', $allIds)
                ->pluck('estimate_id')
                ->merge(\App\Models\EstimateFinanceAllocation::query()->where('contract_id', $contract->id)
                    ->whereIn('estimate_item_id', $allIds)->pluck('estimate_id'))
                ->unique()
                ->values();

            $estimates = Estimate::query()->whereIn('id', $estimateIds)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $contract->project_id)->orderBy('id')->lockForUpdate()->get();
            if ($estimates->count() !== $estimateIds->count()) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            foreach ($estimates as $estimate) {
                $this->financeAdapter->detach($actor, $contract, $estimate, $allIds);
            }
        });
    }

    public function syncItems(Contract $contract, Estimate $estimate, array $itemIds, bool $includeVat = false, ?\App\Models\User $actor = null, ?string $rate = null): Collection
    {
        if ($actor === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        return DB::transaction(function () use ($contract, $estimate, $itemIds, $includeVat, $actor, $rate) {
            Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $contract->project_id)->lockForUpdate()->firstOrFail();
            $allIds = $this->resolveWithChildren($estimate->id, $itemIds);
            $removed = ContractEstimateItem::where('contract_id', $contract->id)
                ->where('estimate_id', $estimate->id)->whereNotIn('estimate_item_id', $allIds)->pluck('estimate_item_id')
                ->merge(\App\Models\EstimateFinanceAllocation::query()->where('contract_id', $contract->id)
                    ->where('estimate_id', $estimate->id)->whereNotIn('estimate_item_id', $allIds)->pluck('estimate_item_id'))
                ->unique()->values()->all();
            $this->financeAdapter->detach($actor, $contract, $estimate, $removed);

            if (empty($itemIds)) {
                return collect();
            }

            return $this->attachItems($contract, $estimate, $itemIds, $includeVat, $actor, $rate);
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
        ])->countedInCoverage()->withFinancialAmounts()->where('contract_id', $contract->id);

        if ($estimateId !== null) {
            $query->where('estimate_id', $estimateId);
        }

        return $query->get();
    }

    public function updateCoverageVat(Contract $contract, Estimate $estimate, bool $includeVat, ?\App\Models\User $actor = null, ?string $rate = null, ?int $revision = null, ?string $mutationId = null): void
    {
        if ($actor === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        if ((int) $contract->organization_id !== (int) $estimate->organization_id || (int) $contract->project_id !== (int) $estimate->project_id) {
            throw new DomainException('contract_estimate_items_invalid');
        }
        $this->financeAdapter->updateVat($actor, $contract, $estimate, $includeVat, $rate, $revision, $mutationId);
    }

    public function getContractsByEstimateItem(EstimateItem $item): Collection
    {
        return $item->contracts()->get();
    }

    public function calculateContractEstimateTotal(Contract $contract, ?int $estimateId = null): ?float
    {
        $query = ContractEstimateItem::query()->countedInCoverage()->withFinancialAmounts()->where('contract_id', $contract->id);

        if ($estimateId !== null) {
            $query->where('estimate_id', $estimateId);
        }

        $total = DB::query()->fromSub($query, 'financial_links')
            ->selectRaw('CASE WHEN COUNT(amount) = COUNT(*) THEN COALESCE(SUM(amount), 0) ELSE NULL END AS total')
            ->value('total');

        return $total === null ? null : round((float) $total, 2);
    }

    public function calculateItemsTotal(Estimate $estimate, array $itemIds, bool $includeVat = false, ?string $rate = null): float
    {
        $allIds = $this->resolveWithChildren($estimate->id, $itemIds);
        if ($allIds === []) {
            return 0.0;
        }

        return (float) $this->financeAdapter->initialAmount($estimate, $allIds, $includeVat, $rate);
    }

    public function getSummary(Contract $contract): array
    {
        $links = ContractEstimateItem::query()->countedInCoverage()->withFinancialAmounts()->where('contract_id', $contract->id)
            ->with('estimateItem')
            ->get();

        $byEstimate = $links->groupBy('estimate_id');

        $estimates = [];
        foreach ($byEstimate as $estimateId => $group) {
            $estimates[] = [
                'estimate_id' => $estimateId,
                'items_count' => $group->count(),
                'total_amount' => \App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAmounts::sum($group, 'amount'),
            ];
        }

        return [
            'contract_id' => $contract->id,
            'total_linked_items' => $links->count(),
            'total_amount' => \App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAmounts::sum($links, 'amount'),
            'by_estimate' => $estimates,
        ];
    }

    private function resolveWithChildren(int $estimateId, array $itemIds): array
    {
        $pending = array_values(array_unique(array_map('intval', $itemIds)));
        if ($pending === []) {
            return [];
        }
        $children = EstimateItem::query()->where('estimate_id', $estimateId)->whereNotNull('parent_work_id')
            ->get(['id', 'parent_work_id'])->groupBy('parent_work_id');
        $seen = array_fill_keys($pending, true);
        for ($index = 0; $index < count($pending); $index++) {
            foreach ($children->get($pending[$index], collect()) as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $pending[] = (int) $child->id;
                }
            }
        }

        return $pending;
    }

    private function resolveChildrenForDetach(int $contractId, array $itemIds): array
    {
        $children = EstimateItem::query()->whereNotNull('parent_work_id')
            ->whereIn('estimate_id', ContractEstimateItem::query()->where('contract_id', $contractId)->select('estimate_id')
                ->union(\App\Models\EstimateFinanceAllocation::query()->where('contract_id', $contractId)->select('estimate_id')))
            ->get(['id', 'parent_work_id'])->groupBy('parent_work_id');
        $pending = array_values(array_unique(array_map('intval', $itemIds)));
        $seen = array_fill_keys($pending, true);
        for ($index = 0; $index < count($pending); $index++) {
            foreach ($children->get($pending[$index], collect()) as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $pending[] = (int) $child->id;
                }
            }
        }

        return $pending;
    }

    private function calculateAmount(EstimateItem $item, Estimate $estimate, bool $includeVat): float
    {
        if ($item->is_not_accounted) {
            return 0.0;
        }

        if ($item->total_amount !== null && (float) $item->total_amount > 0) {
            $amount = (float) $item->total_amount;
        } else {
            $quantity = (float) ($item->quantity_total ?? $item->quantity ?? 0);
            $price = (float) ($item->unit_price ?? 0);
            $amount = $quantity * $price;
        }

        if ($includeVat) {
            $amount *= 1 + ((float) ($estimate->vat_rate ?? 0) / 100);
        }

        return round($amount, 2);
    }
}
