<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceContractBudget
{
    public function summaries(array $contracts, ?Estimate $replacedEstimate = null, array $targetKeys = []): array
    {
        if ($contracts === []) {
            return [];
        }
        $byId = [];
        $states = [];
        foreach ($contracts as $contract) {
            $byId[$contract->id] = $contract;
            $states[$contract->id] = ['sum' => '0.00', 'unknown' => 0, 'bases' => [], 'taxes' => []];
        }
        $selected = array_fill_keys($targetKeys, true);
        $organizationId = reset($contracts)->organization_id;
        foreach (EstimateFinanceAllocation::query()->join('estimates', 'estimates.id', '=', 'estimate_finance_allocations.estimate_id')
            ->where('estimate_finance_allocations.organization_id', $organizationId)->where('estimates.organization_id', $organizationId)
            ->whereIn('estimate_finance_allocations.contract_id', array_keys($byId))->where('estimate_finance_allocations.source', 'contract')
            ->select('estimate_finance_allocations.*')->cursor() as $row) {
            $key = $row->resource_id ? 'r:'.$row->resource_id : 'i:'.$row->estimate_item_id;
            if ($replacedEstimate && (int) $row->estimate_id === (int) $replacedEstimate->id && isset($selected[$key])) {
                continue;
            }
            $state = &$states[$row->contract_id];
            $basis = $row->price_basis;
            $amount = $basis === 'with_vat' ? $row->amount_with_vat : ($basis === 'without_vat' ? $row->amount_without_vat : null);
            if ($amount === null || $row->currency !== ($byId[$row->contract_id]->currency ?: 'RUB')) {
                $state['unknown']++;
            } else {
                $state['sum'] = FinanceDecimal::add($state['sum'], (string) $amount);
                $state['bases'][$basis] = true;
                $state['taxes'][json_encode([EstimateFinanceTax::mode($row->getAttributes()), $row->vat_rate], JSON_THROW_ON_ERROR)] = true;
            }
            unset($state);
        }
        foreach (DB::table('contract_estimate_items as links')->join('estimates as estimates', 'estimates.id', '=', 'links.estimate_id')
            ->where('estimates.organization_id', $organizationId)->whereIn('links.contract_id', array_keys($byId))
            ->where('links.finance_managed', false)->select('links.contract_id', 'links.estimate_id', 'links.estimate_item_id')->cursor() as $row) {
            if ($replacedEstimate && (int) $row->estimate_id === (int) $replacedEstimate->id && isset($selected['i:'.$row->estimate_item_id])) {
                continue;
            }
            $states[$row->contract_id]['unknown']++;
        }
        $result = [];
        foreach ($byId as $id => $contract) {
            $state = $states[$id];
            $total = $contract->is_fixed_amount && $contract->total_amount !== null ? FinanceDecimal::value((string) $contract->total_amount) : null;
            $allocated = $state['unknown'] === 0 && count($state['bases']) <= 1 ? FinanceDecimal::value($state['sum']) : null;
            $remaining = $total !== null && $allocated !== null ? FinanceDecimal::subtract($total, $allocated) : null;
            $tax = count($state['taxes']) === 1 ? json_decode(array_key_first($state['taxes']), true, 512, JSON_THROW_ON_ERROR) : ['unknown', null];
            $result[$id] = [
                'contract_amount' => $total, 'allocated_amount' => $allocated,
                'remaining_amount' => $remaining === null ? null : FinanceDecimal::value(FinanceDecimal::compare($remaining, '0') < 0 ? '0' : $remaining),
                'price_basis' => count($state['bases']) === 1 ? array_key_first($state['bases']) : null,
                'vat_mode' => $tax[0], 'vat_rate' => $tax[1],
                'unpriced_count' => $state['unknown'], 'mixed_basis' => count($state['bases']) > 1,
            ];
        }

        return $result;
    }

    public function assertAvailable(array $contracts, Estimate $estimate, array $targetKeys, array $rows, array $newKeys, array $originalRows): void
    {
        $outside = $this->summaries($contracts, $estimate, $targetKeys);
        $current = $this->summaries($contracts);
        $incoming = [];
        foreach ($rows as $row) {
            if ($row['source'] !== 'contract') {
                continue;
            }
            $incoming[$row['contract_id']][] = $row;
        }
        foreach ($incoming as $id => $lines) {
            $budget = $outside[$id];
            if ($budget['contract_amount'] === null) {
                continue;
            }
            $addsNew = array_intersect(array_column($lines, 'key'), $newKeys) !== [];
            $bases = array_unique(array_column($lines, 'price_basis'));
            $basis = count($bases) === 1 ? $bases[0] : null;
            if ($basis === 'unknown') {
                continue;
            }
            if ($budget['allocated_amount'] === null || $basis === null || $basis === 'unknown'
                || ($budget['price_basis'] !== null && $budget['price_basis'] !== $basis)) {
                if ($addsNew || ! $this->amountsUnchanged($lines, $originalRows)) {
                    throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.contract_budget_unknown')]);
                }
                continue;
            }
            $candidate = $budget['allocated_amount'];
            foreach ($lines as $line) {
                $amount = $line[$basis === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat'];
                if ($amount === null) {
                    continue;
                }
                $candidate = FinanceDecimal::add($candidate, $amount);
            }
            $previous = $current[$id]['price_basis'] === $basis ? $current[$id]['allocated_amount'] : null;
            if (FinanceDecimal::compare($candidate, $budget['contract_amount']) > 0
                && ($previous === null || FinanceDecimal::compare($candidate, $previous) > 0)) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.contract_budget_exceeded')]);
            }
        }
    }

    private function amountsUnchanged(array $lines, array $originalRows): bool
    {
        foreach ($lines as $line) {
            $original = $originalRows[$line['key']] ?? null;
            if ($original === null || (int) $original['contract_id'] !== (int) $line['contract_id']
                || $original['currency'] !== $line['currency'] || $original['price_basis'] !== $line['price_basis']
                || EstimateFinanceTax::mode($original) !== $line['vat_mode']) {
                return false;
            }
            foreach (['amount_without_vat', 'amount_with_vat', 'vat_rate'] as $field) {
                $before = $original[$field] ?? null;
                $after = $line[$field] ?? null;
                if (($before === null) !== ($after === null)
                    || ($before !== null && FinanceDecimal::compare((string) $before, (string) $after) !== 0)) {
                    return false;
                }
            }
        }

        return true;
    }
}
