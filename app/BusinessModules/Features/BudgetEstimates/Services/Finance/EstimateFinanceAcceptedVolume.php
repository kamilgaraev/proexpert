<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceAcceptedVolume
{
    public function assertRetained(Estimate $estimate, array $targetKeys, array $rows): void
    {
        $this->assertManualRetained($estimate, $targetKeys, $rows);
        $itemIds = array_map(static fn (string $key): int => (int) substr($key, 2),
            array_values(array_filter($targetKeys, static fn (string $key): bool => str_starts_with($key, 'i:'))));
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
        foreach ($this->quantities($estimate, array_values(array_unique($contractIds)), $itemIds) as $key => $quantity) {
            if (FinanceDecimal::compare($quantities[$key] ?? '0', $quantity) < 0) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.accepted_volume')]);
            }
        }
        $byKey = array_column($rows, null, 'key');
        foreach ($this->allocationQuantities($estimate, $itemIds) as $accepted) {
            $row = $byKey[$accepted->allocation_key] ?? null;
            if ($row === null || (int) $row['contract_id'] !== (int) $accepted->contract_id
                || (int) $row['estimate_item_id'] !== (int) $accepted->estimate_item_id || $row['resource_id'] !== null
                || FinanceDecimal::compare($row['quantity'], (string) $accepted->quantity) < 0) {
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

    private function allocationQuantities(Estimate $estimate, array $itemIds): iterable
    {
        return DB::table('performance_act_lines as lines')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'lines.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('acts.project_id', $estimate->project_id)->whereNull('acts.annulled_at')->whereIn('acts.status', ['approved', 'signed'])
            ->whereIn('lines.estimate_item_id', $itemIds)->where('lines.basis_snapshot->basis_type', 'contract_conditions')
            ->selectRaw("acts.contract_id, lines.estimate_item_id, lines.basis_snapshot->>'allocation_key' AS allocation_key, SUM(lines.quantity) AS quantity")
            ->groupByRaw("acts.contract_id, lines.estimate_item_id, lines.basis_snapshot->>'allocation_key'")->get();
    }

    public function quantities(Estimate $estimate, array $contractIds, array $itemIds): array
    {
        if ($contractIds === [] || $itemIds === []) {
            return [];
        }
        $acts = DB::table('contract_performance_acts as acts')->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('acts.project_id', $estimate->project_id)->whereIn('acts.contract_id', $contractIds)
            ->whereNull('acts.annulled_at')->whereIn('acts.status', ['approved', 'signed']);
        $lines = (clone $acts)->join('performance_act_lines as lines', 'lines.performance_act_id', '=', 'acts.id')
            ->whereIn('lines.estimate_item_id', $itemIds)
            ->selectRaw('acts.contract_id, lines.estimate_item_id, SUM(lines.quantity) AS quantity')
            ->groupBy('acts.contract_id', 'lines.estimate_item_id')->get();
        $legacy = (clone $acts)->join('performance_act_completed_works as pivot', 'pivot.performance_act_id', '=', 'acts.id')
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

        return $result;
    }
}
