<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceAcceptedVolume
{
    public function assertRetained(Estimate $estimate, array $targetKeys, array $rows): void
    {
        $this->assertManualRetained($estimate, $targetKeys, $rows);
        $itemIds = array_map(static fn (string $key): int => (int) substr($key, 2),
            array_values(array_filter($targetKeys, static fn (string $key): bool => str_starts_with($key, 'i:'))));
        $resourceIds = array_map(static fn (string $key): int => (int) substr($key, 2),
            array_values(array_filter($targetKeys, static fn (string $key): bool => str_starts_with($key, 'r:'))));
        $byKey = array_column($rows, null, 'key');
        foreach ($this->allocationQuantities($estimate, $itemIds, $resourceIds) as $accepted) {
            $row = $byKey[$accepted->allocation_key] ?? null;
            if ($row === null || (int) $row['contract_id'] !== (int) $accepted->contract_id
                || (int) $row['estimate_item_id'] !== (int) $accepted->estimate_item_id
                || (int) $row['resource_id'] !== (int) $accepted->resource_id
                || $row['currency'] !== $accepted->currency
                || FinanceDecimal::compare($row['quantity'], (string) $accepted->quantity) < 0) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.accepted_volume')]);
            }
        }
        if ($itemIds === []) {
            return;
        }
        $contractIds = ContractEstimateItem::query()->where('estimate_id', $estimate->id)
            ->whereIn('estimate_item_id', $itemIds)->pluck('contract_id')->all();
        $quantities = [];
        foreach ($rows as $row) {
            if ($row['contract_id'] === null || $row['resource_id'] !== null) {
                continue;
            }
            $contractIds[] = $row['contract_id'];
            $key = $row['contract_id'].':'.$row['estimate_item_id'];
            $quantities[$key] = FinanceDecimal::add($quantities[$key] ?? '0', $row['quantity']);
        }
        $acceptedQuantities = $this->quantities($estimate, array_values(array_unique($contractIds)), $itemIds);
        $existingQuantities = [];
        if (in_array(null, $acceptedQuantities, true)) {
            $existing = DB::table('estimate_finance_allocations')->where('organization_id', $estimate->organization_id)
                ->where('estimate_id', $estimate->id)->whereNull('resource_id')->whereIn('contract_id', $contractIds)
                ->whereIn('estimate_item_id', $itemIds)->selectRaw('contract_id, estimate_item_id, SUM(quantity) AS quantity')
                ->groupBy('contract_id', 'estimate_item_id')->get();
            foreach ($existing as $condition) {
                $existingQuantities[$condition->contract_id.':'.$condition->estimate_item_id] = (string) $condition->quantity;
            }
        }
        foreach ($acceptedQuantities as $key => $quantity) {
            $minimum = $quantity ?? $existingQuantities[$key] ?? null;
            if ($minimum === null || FinanceDecimal::compare($quantities[$key] ?? '0', $minimum) < 0) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.accepted_volume')]);
            }
        }
    }

    private function assertManualRetained(Estimate $estimate, array $targetKeys, array $rows): void
    {
        $items = [];
        $resources = [];
        foreach ($targetKeys as $key) {
            if (str_starts_with($key, 'r:')) {
                $resources[] = (int) substr($key, 2);
            } else {
                $items[] = (int) substr($key, 2);
            }
        }
        $records = DB::table('estimate_finance_execution_allocations as fact')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'fact.allocation_id')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'fact.performance_act_id')
            ->where('fact.organization_id', $estimate->organization_id)->where('fact.project_id', $estimate->project_id)->where('fact.estimate_id', $estimate->id)
            ->where('acts.project_id', $estimate->project_id)
            ->where(fn ($q) => $q->whereIn('conditions.resource_id', $resources)
                ->orWhere(fn ($itemsQuery) => $itemsQuery->whereNull('conditions.resource_id')->whereIn('conditions.estimate_item_id', $items)))
            ->selectRaw("conditions.key, conditions.contract_id, conditions.estimate_item_id, conditions.resource_id, conditions.currency, conditions.quantity AS planned_quantity, SUM(CASE WHEN acts.status IN ('approved', 'signed') AND acts.annulled_at IS NULL THEN COALESCE(fact.quantity, 0) ELSE 0 END) AS quantity, MAX(CASE WHEN acts.status IN ('approved', 'signed') AND acts.annulled_at IS NULL AND fact.quantity IS NULL AND fact.amount_with_vat > 0 THEN 1 ELSE 0 END) AS unknown_quantity")
            ->groupBy('conditions.key', 'conditions.contract_id', 'conditions.estimate_item_id', 'conditions.resource_id', 'conditions.currency', 'conditions.quantity')->get();
        $byKey = array_column($rows, null, 'key');
        foreach ($records as $fact) {
            $row = $byKey[$fact->key] ?? null;
            $minimum = (int) $fact->unknown_quantity === 1 ? (string) $fact->planned_quantity : (string) $fact->quantity;
            if ($row === null || (int) $row['contract_id'] !== (int) $fact->contract_id
                || (int) $row['estimate_item_id'] !== (int) $fact->estimate_item_id || (int) $row['resource_id'] !== (int) $fact->resource_id
                || $row['currency'] !== $fact->currency || FinanceDecimal::compare($row['quantity'], $minimum) < 0) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.accepted_volume')]);
            }
        }
    }

    private function allocationQuantities(Estimate $estimate, array $itemIds, array $resourceIds): iterable
    {
        return DB::table('performance_act_lines as lines')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'lines.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->join('estimate_finance_allocations as conditions', fn ($join) => $join
                ->whereRaw("conditions.key::text = lines.basis_snapshot->>'allocation_key'")
                ->on('conditions.contract_id', '=', 'acts.contract_id')->on('conditions.estimate_item_id', '=', 'lines.estimate_item_id'))
            ->where('conditions.organization_id', $estimate->organization_id)->where('conditions.estimate_id', $estimate->id)
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('acts.project_id', $estimate->project_id)->whereNull('acts.annulled_at')->whereIn('acts.status', ['approved', 'signed'])
            ->where(fn ($query) => $query->whereIn('conditions.resource_id', $resourceIds)
                ->orWhere(fn ($items) => $items->whereNull('conditions.resource_id')->whereIn('lines.estimate_item_id', $itemIds)))
            ->where('lines.basis_snapshot->basis_type', 'contract_conditions')
            ->whereRaw("lines.basis_snapshot->>'estimate_id' = conditions.estimate_id::text")
            ->whereRaw("lines.basis_snapshot->>'contract_id' = acts.contract_id::text")
            ->whereRaw("lines.basis_snapshot->>'estimate_item_id' = lines.estimate_item_id::text")
            ->selectRaw('acts.contract_id, lines.estimate_item_id, conditions.key AS allocation_key, conditions.resource_id, conditions.currency, SUM(lines.quantity) AS quantity')
            ->groupBy('acts.contract_id', 'lines.estimate_item_id', 'conditions.key', 'conditions.resource_id', 'conditions.currency')->get();
    }

    public function quantities(Estimate $estimate, array $contractIds, array $itemIds, ?int $excludedNativeActId = null): array
    {
        if ($contractIds === [] || $itemIds === []) {
            return [];
        }
        $acts = DB::table('contract_performance_acts as acts')->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('acts.project_id', $estimate->project_id)->whereIn('acts.contract_id', $contractIds)
            ->whereNull('acts.annulled_at')->whereIn('acts.status', ['approved', 'signed']);
        $nativeActs = (clone $acts)->when($excludedNativeActId !== null, fn ($query) => $query->where('acts.id', '!=', $excludedNativeActId));
        $lines = (clone $nativeActs)->join('performance_act_lines as lines', 'lines.performance_act_id', '=', 'acts.id')
            ->whereIn('lines.estimate_item_id', $itemIds)
            ->whereNotExists($this->mappedResources($estimate))
            ->selectRaw('acts.contract_id, lines.estimate_item_id, SUM(lines.quantity) AS quantity')
            ->groupBy('acts.contract_id', 'lines.estimate_item_id')->get();
        $legacy = (clone $nativeActs)->join('performance_act_completed_works as pivot', 'pivot.performance_act_id', '=', 'acts.id')
            ->join('completed_works as works', 'works.id', '=', 'pivot.completed_work_id')
            ->where('works.organization_id', $estimate->organization_id)->where('works.project_id', $estimate->project_id)
            ->whereIn('works.estimate_item_id', $itemIds)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('performance_act_lines')->whereColumn('performance_act_id', 'acts.id');
            })
            ->selectRaw('acts.contract_id, works.estimate_item_id, SUM(pivot.included_quantity) AS quantity')
            ->groupBy('acts.contract_id', 'works.estimate_item_id')->get();
        $result = [];
        foreach ($lines->concat($legacy) as $line) {
            $key = $line->contract_id.':'.$line->estimate_item_id;
            $result[$key] = FinanceDecimal::add($result[$key] ?? '0', (string) $line->quantity);
        }
        $manual = (clone $acts)->join('estimate_finance_execution_allocations as fact', 'fact.performance_act_id', '=', 'acts.id')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'fact.allocation_id')
            ->where('fact.organization_id', $estimate->organization_id)->where('fact.project_id', $estimate->project_id)
            ->where('fact.estimate_id', $estimate->id)->where('conditions.organization_id', $estimate->organization_id)
            ->where('conditions.estimate_id', $estimate->id)->whereColumn('conditions.contract_id', 'acts.contract_id')
            ->whereNull('conditions.resource_id')->whereIn('conditions.estimate_item_id', $itemIds)
            ->selectRaw('acts.contract_id, conditions.estimate_item_id, SUM(COALESCE(fact.quantity, 0)) AS quantity, MAX(CASE WHEN fact.quantity IS NULL AND fact.amount_with_vat > 0 THEN 1 ELSE 0 END) AS unknown_quantity')
            ->groupBy('acts.contract_id', 'conditions.estimate_item_id')->get();
        foreach ($manual as $line) {
            $key = $line->contract_id.':'.$line->estimate_item_id;
            $result[$key] = (int) $line->unknown_quantity === 1 ? null : FinanceDecimal::add($result[$key] ?? '0', (string) $line->quantity);
        }

        return $result;
    }

    private function mappedResources(Estimate $estimate): Builder
    {
        return DB::table('estimate_finance_allocations as resource_conditions')
            ->join('estimate_item_resources as resources', 'resources.id', '=', 'resource_conditions.resource_id')
            ->where('resource_conditions.organization_id', $estimate->organization_id)->where('resource_conditions.estimate_id', $estimate->id)
            ->whereColumn('resource_conditions.contract_id', 'acts.contract_id')->whereColumn('resource_conditions.estimate_item_id', 'lines.estimate_item_id')
            ->whereColumn('resources.estimate_item_id', 'lines.estimate_item_id')
            ->whereRaw("resource_conditions.key::text = lines.basis_snapshot->>'allocation_key'")
            ->where('lines.basis_snapshot->basis_type', 'contract_conditions')
            ->whereRaw("lines.basis_snapshot->>'estimate_id' = resource_conditions.estimate_id::text")
            ->whereRaw("lines.basis_snapshot->>'contract_id' = acts.contract_id::text")
            ->whereRaw("lines.basis_snapshot->>'estimate_item_id' = lines.estimate_item_id::text")
            ->selectRaw('1');
    }
}
