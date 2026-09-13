<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class EstimateFinanceActQuantityGuard
{
    public function __construct(private readonly EstimateFinanceAcceptedVolume $accepted, private readonly EstimateFinanceExecutionQuantity $quantities) {}

    public function assertFits(ContractPerformanceAct $act, Contract $contract): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('finance_act_quantity_requires_transaction');
        }
        if ((int) $act->contract_id !== (int) $contract->id) {
            $this->invalid();
        }
        $lines = $act->lines()->get(['estimate_item_id', 'quantity', 'amount', 'basis_snapshot']);
        if ($lines->isEmpty()) {
            $lines = $act->completedWorks()->where('organization_id', $contract->organization_id)->where('project_id', $act->project_id)
                ->get()->map(static fn ($work): array => ['estimate_item_id' => $work->estimate_item_id, 'quantity' => $work->pivot->included_quantity]);
        }
        $requested = [];
        foreach ($lines as $line) {
            if ($line['estimate_item_id'] === null) {
                continue;
            }
            $itemId = (int) $line['estimate_item_id'];
            $previous = array_key_exists($itemId, $requested) ? $requested[$itemId] : '0';
            $requested[$itemId] = $previous === null || $line['quantity'] === null ? null : FinanceDecimal::add($previous, (string) $line['quantity']);
        }
        if ($requested === []) {
            return;
        }
        $conditions = DB::table('estimate_finance_allocations as conditions')->join('estimates', 'estimates.id', '=', 'conditions.estimate_id')
            ->join('estimate_items as items', 'items.id', '=', 'conditions.estimate_item_id')
            ->where('conditions.organization_id', $contract->organization_id)->where('conditions.contract_id', $contract->id)
            ->where('estimates.organization_id', $contract->organization_id)->where('estimates.project_id', $act->project_id)
            ->whereColumn('items.estimate_id', 'conditions.estimate_id')
            ->whereIn('conditions.estimate_item_id', array_keys($requested))
            ->selectRaw('conditions.estimate_id, conditions.estimate_item_id, conditions.resource_id, SUM(conditions.quantity) AS quantity')
            ->groupBy('conditions.estimate_id', 'conditions.estimate_item_id', 'conditions.resource_id')->get()->groupBy('estimate_id');
        $estimates = Estimate::query()->where('organization_id', $contract->organization_id)->where('project_id', $act->project_id)
            ->whereIn('id', $conditions->keys())->get()->keyBy('id');
        foreach ($conditions as $estimateId => $items) {
            $estimate = $estimates->get($estimateId);
            if ($estimate === null) {
                $this->invalid();
            }
            $resourceQuantities = $this->assertConditionQuantities($estimate, $contract, $act, $lines);
            $accepted = $this->accepted->quantities($estimate, [(int) $contract->id], $items->pluck('estimate_item_id')->unique()->all(), (int) $act->id);
            foreach ($items as $item) {
                if ($item->resource_id !== null) {
                    continue;
                }
                $key = $contract->id.':'.$item->estimate_item_id;
                $done = array_key_exists($key, $accepted) ? $accepted[$key] : '0';
                $quantity = $requested[$item->estimate_item_id] === null ? null
                    : FinanceDecimal::subtract($requested[$item->estimate_item_id], $resourceQuantities[$item->estimate_item_id] ?? '0');
                if ($done === null || $quantity === null || FinanceDecimal::compare(FinanceDecimal::add($done, $quantity), (string) $item->quantity) > 0) {
                    $this->invalid();
                }
            }
        }
    }

    private function assertConditionQuantities(Estimate $estimate, Contract $contract, ContractPerformanceAct $act, Collection $lines): array
    {
        $keys = $lines->map(fn ($line) => data_get($line, 'basis_snapshot.allocation_key'))
            ->filter(fn ($key) => is_string($key) && Str::isUuid($key))->unique();
        $conditions = EstimateFinanceAllocation::query()->where('organization_id', $estimate->organization_id)
            ->where('estimate_id', $estimate->id)->where('contract_id', $contract->id)
            ->whereIn('key', $keys)->get()->keyBy('key');
        $changes = [];
        $resources = [];
        foreach ($lines as $line) {
            $snapshot = data_get($line, 'basis_snapshot', []);
            $condition = $conditions->get($snapshot['allocation_key'] ?? '');
            if ($condition === null) {
                if (($snapshot['basis_type'] ?? null) === 'contract_conditions' && (int) ($snapshot['estimate_id'] ?? 0) === (int) $estimate->id) {
                    $this->invalid();
                }
                continue;
            }
            if (($snapshot['basis_type'] ?? null) !== 'contract_conditions'
                || (int) ($snapshot['estimate_id'] ?? 0) !== (int) $estimate->id
                || (int) ($snapshot['contract_id'] ?? 0) !== (int) $contract->id
                || (int) ($snapshot['estimate_item_id'] ?? 0) !== (int) $condition->estimate_item_id
                || (int) $line['estimate_item_id'] !== (int) $condition->estimate_item_id) {
                $this->invalid();
            }
            if ($condition->resource_id !== null) {
                if ($line['quantity'] === null) {
                    $this->invalid();
                }
                $resources[$condition->estimate_item_id] = FinanceDecimal::add($resources[$condition->estimate_item_id] ?? '0', (string) $line['quantity']);
            }
            $previous = $changes[$condition->id] ?? ['allocation' => $condition, 'quantity' => '0', 'amount' => '0'];
            $previous['quantity'] = $previous['quantity'] === null || $line['quantity'] === null ? null
                : FinanceDecimal::add($previous['quantity'], (string) $line['quantity']);
            $previous['amount'] = FinanceDecimal::add($previous['amount'], (string) $line['amount']);
            $changes[$condition->id] = $previous;
        }
        $this->quantities->assertAvailable($estimate, (int) $contract->id, (int) $act->id, $changes, true);

        return $resources;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.execution_quantity_invalid')]);
    }
}
