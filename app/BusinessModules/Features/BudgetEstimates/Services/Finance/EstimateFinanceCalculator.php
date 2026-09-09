<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceCalculator
{
    public function calculate(array $targets, array $allocations, string $basis): array
    {
        $byTarget = [];
        foreach ($allocations as $allocation) {
            $byTarget[$allocation['target_key']][] = $allocation;
        }
        $rows = [];
        $children = [];
        foreach ($targets as $key => $target) {
            if ($target['parent_key']) {
                $children[$target['parent_key']][] = $key;
            }
            $lines = $byTarget[$key] ?? [];
            $revenue = '0.00';
            $cost = '0.00';
            $revenueQuantity = '0';
            $costQuantity = '0';
            $currencies = [];
            $warnings = [];
            if ($target['source_changed'] ?? false) {
                $warnings[] = 'estimate_changed';
            }
            if (! empty($target['pending_resources'])) {
                $warnings[] = 'pending_resources';
            }
            if ($target['representation_needs_review'] ?? false) {
                $warnings[] = 'resource_representation';
            }
            $money = [];
            $field = $basis === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat';
            foreach ($lines as $line) {
                if ($line['source'] === 'included') {
                    $costQuantity = FinanceDecimal::add($costQuantity, $line['quantity']);

                    continue;
                }
                $currencies[$line['currency']] = true;
                $money[$line['currency']] ??= $this->emptyMoney($line['currency']);
                $amount = $line[$field] ?? null;
                if ($amount === null) {
                    $warnings[] = 'unknown_price_or_tax';
                }
                if ($line['vat_rate'] === null || $line['price_basis'] === 'unknown') {
                    $warnings[] = 'unknown_price_or_tax';
                }
                if (($line['legacy'] ?? false) || ! $line['composition_confirmed']) {
                    $warnings[] = 'requires_review';
                }
                $snapshot = $line['estimate_snapshot'] ?? null;
                if ($snapshot !== null && (FinanceDecimal::compare($snapshot['quantity'], $target['quantity']) !== 0
                    || FinanceDecimal::compare($snapshot['estimate_amount'], $target['estimate_amount']) !== 0 || $snapshot['unit_id'] !== $target['unit_id'])) {
                    $warnings[] = 'estimate_changed';
                }
                if ($line['side'] === 'revenue') {
                    if ($target['parent_key']) {
                        $warnings[] = 'child_revenue_requires_review';
                    } else {
                        $money[$line['currency']]['revenue'] = FinanceDecimal::add($money[$line['currency']]['revenue'], $amount ?? '0');
                    }
                    $revenue = FinanceDecimal::add($revenue, $amount ?? '0');
                    $revenueQuantity = FinanceDecimal::add($revenueQuantity, $line['quantity']);
                } elseif ($line['side'] === 'cost') {
                    $category = $line['source'] === 'own' ? 'own_cost' : 'contract_cost';
                    foreach (['cost', $category] as $metric) {
                        $money[$line['currency']][$metric] = FinanceDecimal::add($money[$line['currency']][$metric], $amount ?? '0');
                    }
                    $cost = FinanceDecimal::add($cost, $amount ?? '0');
                    $costQuantity = FinanceDecimal::add($costQuantity, $line['quantity']);
                } else {
                    $warnings[] = 'unknown_side';
                }
            }
            if (! $target['parent_key'] && (FinanceDecimal::compare($revenueQuantity, $target['quantity']) !== 0 || ! array_filter($lines, static fn (array $line): bool => $line['side'] === 'revenue'))) {
                $warnings[] = 'revenue_unallocated';
            }
            if (! $target['parent_key'] && (FinanceDecimal::compare($costQuantity, $target['quantity']) !== 0 || ! array_filter($lines, static fn (array $line): bool => $line['side'] === 'cost'))) {
                $warnings[] = 'cost_unallocated';
            }
            if (FinanceDecimal::compare($costQuantity, $target['quantity']) > 0 || FinanceDecimal::compare($revenueQuantity, $target['quantity']) > 0) {
                $warnings[] = 'overallocated';
            }
            if (count($currencies) > 1) {
                $warnings[] = 'mixed_currency';
            }
            $currency = count($currencies) === 1 ? array_key_first($currencies) : (count($currencies) ? null : 'RUB');
            $rows[$key] = $target + [
                'allocations' => $lines, 'links_count' => count($lines), 'currency' => $currency,
                'revenue' => $currency === null ? null : FinanceDecimal::value($revenue),
                'cost' => $currency === null ? null : FinanceDecimal::value($cost),
                'revenue_quantity' => $revenueQuantity, 'cost_quantity' => $costQuantity,
                'included_quantity' => $target['parent_key'] && FinanceDecimal::compare($target['quantity'], $costQuantity) > 0
                    ? FinanceDecimal::subtract($target['quantity'], $costQuantity) : '0',
                'warnings' => array_values(array_unique($warnings)),
                'by_currency' => $money,
            ];
        }
        $visited = [];
        $roll = function (string $key, array $ancestors = []) use (&$roll, &$rows, &$visited, $children): void {
            if (isset($visited[$key]) || isset($ancestors[$key])) {
                return;
            }
            $ancestors[$key] = true;
            foreach ($children[$key] ?? [] as $childKey) {
                if ($rows[$childKey]['excluded']) {
                    continue;
                }
                $roll($childKey, $ancestors);
                $child = $rows[$childKey];
                foreach ($child['by_currency'] as $currency => $amounts) {
                    $rows[$key]['by_currency'][$currency] ??= $this->emptyMoney($currency);
                    foreach (['cost', 'own_cost', 'contract_cost'] as $metric) {
                        $rows[$key]['by_currency'][$currency][$metric] = FinanceDecimal::add($rows[$key]['by_currency'][$currency][$metric], $amounts[$metric]);
                    }
                }
                $rows[$key]['warnings'] = array_values(array_unique(array_merge($rows[$key]['warnings'], $child['warnings'])));
            }
            $row = &$rows[$key];
            if ($row['by_currency'] === [] && ! $row['parent_key']) {
                $row['by_currency']['RUB'] = $this->emptyMoney('RUB');
            }
            if (count($row['by_currency']) > 1) {
                $row['currency'] = null;
                $row['revenue'] = null;
                $row['cost'] = null;
                $row['warnings'] = array_values(array_unique([...$row['warnings'], 'mixed_currency']));
            } elseif (count($row['by_currency']) === 1) {
                $row['currency'] = array_key_first($row['by_currency']);
                $row['revenue'] = FinanceDecimal::value($row['by_currency'][$row['currency']]['revenue']);
                $row['cost'] = FinanceDecimal::value($row['by_currency'][$row['currency']]['cost']);
            }
            $row['complete'] = ! $row['excluded'] && $row['warnings'] === [];
            $row['margin'] = ! $row['parent_key'] && $row['complete'] && $row['revenue'] !== null && $row['cost'] !== null
                ? FinanceDecimal::value(FinanceDecimal::subtract($row['revenue'], $row['cost'])) : null;
            $row['margin_percent'] = $row['margin'] !== null && FinanceDecimal::compare($row['revenue'], '0') > 0
                ? FinanceDecimal::divide(FinanceDecimal::multiply($row['margin'], '100'), $row['revenue']) : null;
            $visited[$key] = true;
        };
        foreach (array_keys($rows) as $key) {
            $roll($key);
        }

        return ['rows' => array_values($rows), 'totals' => $this->totals(array_values($rows))];
    }

    public function totals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            if ($row['parent_key'] || $row['excluded']) {
                continue;
            }
            foreach ($row['by_currency'] as $currency => $money) {
                $totals[$currency] ??= $this->emptyMoney($currency) + ['complete_margin' => '0.00', 'incomplete_count' => 0];
                foreach (['revenue', 'cost', 'own_cost', 'contract_cost'] as $field) {
                    $totals[$currency][$field] = FinanceDecimal::value(FinanceDecimal::add($totals[$currency][$field], $money[$field]));
                }
                if ($row['margin'] === null) {
                    $totals[$currency]['incomplete_count']++;
                } else {
                    $totals[$currency]['complete_margin'] = FinanceDecimal::value(FinanceDecimal::add($totals[$currency]['complete_margin'], $row['margin']));
                }
            }
        }
        ksort($totals);

        return array_values($totals);
    }

    private function emptyMoney(string $currency): array
    {
        return ['currency' => $currency, 'revenue' => '0.00', 'cost' => '0.00', 'own_cost' => '0.00', 'contract_cost' => '0.00'];
    }
}
