<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceExecutionQuantity
{
    public function assertAvailable(Estimate $estimate, int $contractId, int $actId, array $changes, bool $excludeNativeAct = false): void
    {
        if ($changes === []) {
            return;
        }
        $itemIds = array_values(array_unique(array_map(fn ($change) => (int) $change['allocation']->estimate_item_id, $changes)));
        $conditions = EstimateFinanceAllocation::query()->where('organization_id', $estimate->organization_id)->where('estimate_id', $estimate->id)
            ->where('contract_id', $contractId)->whereIn('estimate_item_id', $itemIds)->get()->keyBy('id');
        $byKey = $conditions->keyBy('key');
        $planned = [];
        $totals = [];
        $unknown = [];
        $perAllocation = [];
        foreach ($conditions as $condition) {
            $group = $this->group((int) $condition->estimate_item_id, $condition->resource_id);
            $planned[$group] = FinanceDecimal::add($planned[$group] ?? '0', (string) $condition->quantity);
        }
        $acts = DB::table('contract_performance_acts as acts')->where('acts.contract_id', $contractId)->where('acts.project_id', $estimate->project_id)
            ->whereIn('acts.status', ['approved', 'signed'])->whereNull('acts.annulled_at');
        $nativeActs = (clone $acts)->when($excludeNativeAct, fn ($query) => $query->where('acts.id', '!=', $actId));
        $native = (clone $nativeActs)->join('performance_act_lines as line', 'line.performance_act_id', '=', 'acts.id')->whereIn('line.estimate_item_id', $itemIds)
            ->selectRaw("line.estimate_item_id, line.quantity, line.amount, line.basis_snapshot->>'allocation_key' AS allocation_key, line.basis_snapshot->>'basis_type' AS basis_type, line.basis_snapshot->>'estimate_item_id' AS snapshot_item_id, line.basis_snapshot->>'estimate_id' AS snapshot_estimate_id, line.basis_snapshot->>'contract_id' AS snapshot_contract_id")->get();
        $legacy = (clone $nativeActs)->join('performance_act_completed_works as pivot', 'pivot.performance_act_id', '=', 'acts.id')
            ->join('completed_works as work', 'work.id', '=', 'pivot.completed_work_id')->where('work.organization_id', $estimate->organization_id)
            ->where('work.project_id', $estimate->project_id)->whereIn('work.estimate_item_id', $itemIds)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('performance_act_lines')->whereColumn('performance_act_id', 'acts.id'))
            ->selectRaw('work.estimate_item_id, pivot.included_quantity AS quantity, pivot.included_amount AS amount, NULL AS allocation_key, NULL AS basis_type, NULL AS snapshot_item_id, NULL AS snapshot_estimate_id, NULL AS snapshot_contract_id')->get();
        foreach ($native->concat($legacy) as $row) {
            $condition = $row->allocation_key === null ? null : $byKey->get($row->allocation_key);
            if ($condition && ($row->basis_type !== 'contract_conditions'
                || (int) $condition->estimate_item_id !== (int) $row->estimate_item_id
                || (int) $row->snapshot_item_id !== (int) $row->estimate_item_id
                || (int) $row->snapshot_estimate_id !== (int) $estimate->id || (int) $row->snapshot_contract_id !== $contractId)) {
                $condition = null;
            }
            $group = $this->group((int) $row->estimate_item_id, $condition?->resource_id);
            $this->add($totals, $unknown, $group, $row->quantity, $row->amount);
            if ($condition && $row->quantity !== null) {
                $perAllocation[$condition->id] = FinanceDecimal::add($perAllocation[$condition->id] ?? '0', (string) $row->quantity);
            }
        }
        $ledger = DB::table('estimate_finance_execution_allocations as fact')->join('contract_performance_acts as acts', 'acts.id', '=', 'fact.performance_act_id')
            ->where('fact.organization_id', $estimate->organization_id)->where('fact.project_id', $estimate->project_id)->where('fact.estimate_id', $estimate->id)
            ->whereIn('fact.allocation_id', $conditions->keys())->where('acts.contract_id', $contractId)->where('acts.project_id', $estimate->project_id)
            ->whereIn('acts.status', ['approved', 'signed'])->whereNull('acts.annulled_at')->select('fact.*')->get();
        foreach ($ledger as $row) {
            if (! $excludeNativeAct && (int) $row->performance_act_id === $actId && isset($changes[$row->allocation_id])) {
                continue;
            }
            $condition = $conditions->get($row->allocation_id);
            $group = $this->group((int) $condition->estimate_item_id, $condition->resource_id);
            $this->add($totals, $unknown, $group, $row->quantity, $row->amount_with_vat);
            if ($row->quantity !== null) {
                $perAllocation[$condition->id] = FinanceDecimal::add($perAllocation[$condition->id] ?? '0', (string) $row->quantity);
            }
        }
        $changedGroups = [];
        foreach ($changes as $change) {
            $group = $this->group((int) $change['allocation']->estimate_item_id, $change['allocation']->resource_id);
            $changedGroups[$group] = true;
            if ($change['quantity'] === null && FinanceDecimal::compare($change['amount'], '0') > 0) {
                $unknown[$group] = true;
            }
        }
        foreach ($changes as $id => $change) {
            $condition = $change['allocation'];
            $group = $this->group((int) $condition->estimate_item_id, $condition->resource_id);
            if ($change['quantity'] !== null && FinanceDecimal::compare($change['quantity'], '0') > 0 && ($unknown[$group] ?? false)) {
                $this->invalid();
            }
            $this->add($totals, $unknown, $group, $change['quantity'], $change['amount']);
            $perAllocation[$id] = FinanceDecimal::add($perAllocation[$id] ?? '0', $change['quantity'] ?? '0');
            if (FinanceDecimal::compare($perAllocation[$id], (string) $condition->quantity) > 0) {
                $this->invalid();
            }
        }
        foreach ($totals as $group => $quantity) {
            if (isset($changedGroups[$group]) && FinanceDecimal::compare($quantity, $planned[$group] ?? '0') > 0) {
                $this->invalid();
            }
        }
    }

    private function group(int $itemId, mixed $resourceId): string
    {
        return $resourceId === null ? 'i:'.$itemId : 'r:'.$resourceId;
    }

    private function add(array &$totals, array &$unknown, string $group, mixed $quantity, mixed $amount): void
    {
        $totals[$group] = FinanceDecimal::add($totals[$group] ?? '0', $quantity === null ? '0' : (string) $quantity);
        if ($quantity === null && $amount !== null && FinanceDecimal::compare((string) $amount, '0') > 0) {
            $unknown[$group] = true;
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.execution_quantity_invalid')]);
    }
}
