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
