<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class EstimateFinanceActQuantityGuard
{
    public function __construct(private readonly EstimateFinanceAcceptedVolume $accepted) {}

    public function assertFits(ContractPerformanceAct $act, Contract $contract): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('finance_act_quantity_requires_transaction');
        }
        if ((int) $act->contract_id !== (int) $contract->id) {
            $this->invalid();
        }
        $lines = $act->lines()->get(['estimate_item_id', 'quantity']);
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
            ->whereColumn('items.estimate_id', 'conditions.estimate_id')->whereNull('conditions.resource_id')
            ->whereIn('conditions.estimate_item_id', array_keys($requested))
            ->selectRaw('conditions.estimate_id, conditions.estimate_item_id, SUM(conditions.quantity) AS quantity')
            ->groupBy('conditions.estimate_id', 'conditions.estimate_item_id')->get()->groupBy('estimate_id');
        $estimates = Estimate::query()->where('organization_id', $contract->organization_id)->where('project_id', $act->project_id)
            ->whereIn('id', $conditions->keys())->get()->keyBy('id');
        foreach ($conditions as $estimateId => $items) {
            $estimate = $estimates->get($estimateId);
            if ($estimate === null) {
                $this->invalid();
            }
            $accepted = $this->accepted->quantities($estimate, [(int) $contract->id], $items->pluck('estimate_item_id')->all(), (int) $act->id);
            foreach ($items as $item) {
                $key = $contract->id.':'.$item->estimate_item_id;
                $done = array_key_exists($key, $accepted) ? $accepted[$key] : '0';
                $quantity = $requested[$item->estimate_item_id];
                if ($done === null || $quantity === null || FinanceDecimal::compare(FinanceDecimal::add($done, $quantity), (string) $item->quantity) > 0) {
                    $this->invalid();
                }
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.execution_quantity_invalid')]);
    }
}
