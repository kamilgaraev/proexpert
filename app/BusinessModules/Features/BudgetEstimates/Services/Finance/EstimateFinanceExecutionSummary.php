<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceExecutionSummary
{
    public function calculate(array $facts, array $allocations, array $targets, array $sections, string $basis): array
    {
        $amountField = $basis === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat';
        $totals = [];
        $positions = [];
        $sectionTotals = [];
        $contracts = [];
        $sectionParents = array_column($sections, 'parent_section_id', 'id');
        foreach ($allocations as $allocation) {
            if (! $allocation['contract_id']) {
                continue;
            }
            $key = $allocation['contract_id'].':'.$allocation['target_key'].':'.$allocation['currency'];
            $contracts[$key] ??= ['contract_id' => $allocation['contract_id'], 'target_key' => $allocation['target_key'],
                'currency' => $allocation['currency'], 'planned_quantity' => '0', 'accepted_quantity' => '0'];
            $contracts[$key]['planned_quantity'] = FinanceDecimal::add($contracts[$key]['planned_quantity'], $allocation['quantity']);
        }
        $seen = [];
        foreach ($facts as $fact) {
            $sourceKey = $fact['act_id'].':'.$fact['source_type'].':'.$fact['source_id'];
            if (isset($seen[$sourceKey])) {
                continue;
            }
            $seen[$sourceKey] = true;
            $targetKey = ! empty($fact['resource_id']) ? 'r:'.$fact['resource_id'] : 'i:'.$fact['item_id'];
            $target = $targets[$targetKey] ?? null;
            $currency = $fact['currency'];
            $totals[$currency] ??= $this->emptyTotals();
            $positions[$targetKey] ??= ['target_key' => $targetKey, 'item_id' => $fact['item_id'],
                'section_id' => $target['section_id'] ?? null, 'parent_key' => $target['parent_key'] ?? null,
                'currencies' => [], 'sources' => []];
            $positions[$targetKey]['currencies'][$currency] ??= $this->emptyTotals();
            $positions[$targetKey]['sources'][] = $sourceKey;
            $this->add($totals[$currency], $fact, $amountField);
            $this->add($positions[$targetKey]['currencies'][$currency], $fact, $amountField);
            $sectionId = $target['section_id'] ?? null;
            $ancestors = [];
            while ($sectionId && ! isset($ancestors[$sectionId])) {
                $ancestors[$sectionId] = true;
                $sectionTotals[$sectionId][$currency] ??= $this->emptyTotals();
                $this->add($sectionTotals[$sectionId][$currency], $fact, $amountField);
                $sectionId = $sectionParents[$sectionId] ?? null;
            }
            $key = $fact['contract_id'].':'.$targetKey.':'.$currency;
            $contracts[$key] ??= ['contract_id' => $fact['contract_id'], 'target_key' => $targetKey,
                'currency' => $currency, 'planned_quantity' => null, 'accepted_quantity' => '0'];
            $contracts[$key]['accepted_quantity'] = $contracts[$key]['accepted_quantity'] === null || $fact['quantity'] === null
                ? null : FinanceDecimal::add($contracts[$key]['accepted_quantity'], $fact['quantity']);
        }
        foreach ($totals as &$total) {
            $total = $this->finish($total);
        }
        unset($total);
        foreach ($positions as &$position) {
            foreach ($position['currencies'] as &$total) {
                $total = $this->finish($total);
            }
            unset($total);
        }
        unset($position);
        foreach ($sectionTotals as &$currencies) {
            foreach ($currencies as &$total) {
                $total = $this->finish($total);
            }
            unset($total);
        }
        unset($currencies);
        foreach ($contracts as &$contract) {
            $remaining = $contract['planned_quantity'] === null || $contract['accepted_quantity'] === null ? null
                : FinanceDecimal::subtract($contract['planned_quantity'], $contract['accepted_quantity']);
            $overrun = $remaining !== null && FinanceDecimal::compare($remaining, '0') < 0;
            $contract['remaining_quantity'] = $remaining === null ? null : FinanceDecimal::value($overrun ? '0' : $remaining, 8);
            $contract['overrun_quantity'] = $remaining === null ? null : FinanceDecimal::value($overrun ? FinanceDecimal::subtract('0', $remaining) : '0', 8);
            $contract['accepted_quantity'] = $contract['accepted_quantity'] === null ? null : FinanceDecimal::value($contract['accepted_quantity'], 8);
            $contract['planned_quantity'] = $contract['planned_quantity'] === null ? null : FinanceDecimal::value($contract['planned_quantity'], 8);
        }
        unset($contract);

        return ['basis' => $basis, 'totals' => $totals, 'positions' => array_values($positions),
            'sections' => $sectionTotals, 'contract_quantities' => array_values($contracts)];
    }

    private function emptyTotals(): array
    {
        return ['revenue' => '0', 'cost' => '0', 'revenue_unpriced_count' => 0,
            'cost_unpriced_count' => 0, 'unknown_direction_count' => 0, 'sources_count' => 0];
    }

    private function add(array &$total, array $fact, string $amountField): void
    {
        $total['sources_count']++;
        $side = $fact['side'];
        if (! in_array($side, ['revenue', 'cost'], true)) {
            $total['unknown_direction_count']++;

            return;
        }
        if ($fact[$amountField] === null) {
            $total[$side.'_unpriced_count']++;
        } else {
            $total[$side] = FinanceDecimal::add($total[$side], $fact[$amountField]);
        }
    }

    private function finish(array $total): array
    {
        $total['known_revenue'] = FinanceDecimal::value($total['revenue']);
        $total['known_cost'] = FinanceDecimal::value($total['cost']);
        $total['revenue'] = $total['revenue_unpriced_count'] > 0 || $total['unknown_direction_count'] > 0 ? null : $total['known_revenue'];
        $total['cost'] = $total['cost_unpriced_count'] > 0 || $total['unknown_direction_count'] > 0 ? null : $total['known_cost'];
        $total['difference'] = $total['revenue'] === null || $total['cost'] === null || $total['unknown_direction_count'] > 0
            ? null : FinanceDecimal::subtract($total['revenue'], $total['cost']);

        return $total;
    }
}
