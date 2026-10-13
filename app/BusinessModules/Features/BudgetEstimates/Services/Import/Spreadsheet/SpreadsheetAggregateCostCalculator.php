<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SpreadsheetAggregateCostCalculator
{
    public function calculate(array $positions, float $index, int $expensePrecision, int $amountPrecision): array
    {
        $baseDirect = [];
        $baseOverhead = array_fill_keys(array_keys($positions), '0');
        $baseProfit = $baseOverhead;
        $groups = [];

        foreach ($positions as $rowNumber => $position) {
            $baseDirect[$rowNumber] = (string) ($position['base_total'] ?? 0);
            foreach (['overhead', 'profit'] as $charge) {
                $rule = $position[$charge.'_rule'] ?? null;
                if ($rule === null) {
                    if (($position[$charge] ?? 0) != 0) {
                        throw new \InvalidArgumentException('spreadsheet_expense_rule_missing');
                    }

                    continue;
                }
                $key = $charge.':'.(string) BigDecimal::of((string) $rule['rate'])->strippedOfTrailingZeros();
                $groups[$key]['charge'] = $charge;
                $groups[$key]['parts'][$rowNumber] = (string) BigDecimal::of((string) $rule['basis'])
                    ->multipliedBy((string) $rule['rate'])->multipliedBy('0.01');
            }
        }

        foreach ($groups as $group) {
            $target = $this->sum($group['parts'])->toScale($expensePrecision, RoundingMode::HalfUp);
            foreach ($this->allocate($group['parts'], $target, $expensePrecision) as $rowNumber => $amount) {
                if ($group['charge'] === 'overhead') {
                    $baseOverhead[$rowNumber] = $amount;
                } else {
                    $baseProfit[$rowNumber] = $amount;
                }
            }
        }

        $baseComponents = [
            'direct_costs' => $this->sum($baseDirect),
            'overhead_cost' => $this->sum($baseOverhead),
            'profit_cost' => $this->sum($baseProfit),
        ];
        $indexedComponents = array_map(static fn (BigDecimal $amount): string => (string) $amount->multipliedBy((string) $index), $baseComponents);
        $total = $this->sum($indexedComponents)->toScale($amountPrecision, RoundingMode::HalfUp);
        $components = $this->allocate($indexedComponents, $total, $amountPrecision);
        $indexedRows = [];
        foreach (['direct_costs' => $baseDirect, 'overhead_cost' => $baseOverhead, 'profit_cost' => $baseProfit] as $component => $parts) {
            $parts = array_map(static fn (string $amount): string => (string) BigDecimal::of($amount)->multipliedBy((string) $index), $parts);
            $indexedRows[$component] = $this->allocate($parts, BigDecimal::of($components[$component]), $amountPrecision);
        }

        $result = [];
        foreach ($positions as $rowNumber => $position) {
            $direct = BigDecimal::of($indexedRows['direct_costs'][$rowNumber]);
            $overhead = BigDecimal::of($indexedRows['overhead_cost'][$rowNumber]);
            $profit = BigDecimal::of($indexedRows['profit_cost'][$rowNumber]);
            $quantity = $position['quantity'] ?? 0;
            $result[$rowNumber] = [
                'total' => $direct->plus($overhead)->plus($profit)->toFloat(),
                'overhead' => $overhead->toFloat(),
                'profit' => $profit->toFloat(),
                'base_price' => $position['base_price'],
                'index' => $index,
                'current_price' => $quantity != 0 ? $direct->dividedBy((string) $quantity, 8, RoundingMode::HalfUp)->toFloat() : $position['base_price'],
            ];
        }

        return [
            'positions' => $result,
            'totals' => array_map(static fn (string $amount): float => (float) $amount, $components) + ['total_estimate_cost' => $total->toFloat()],
            'base_totals' => array_map(static fn (BigDecimal $amount): float => $amount->toFloat(), $baseComponents),
        ];
    }

    private function sum(array $amounts): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->plus($amount);
        }

        return $sum;
    }

    private function allocate(array $parts, BigDecimal $target, int $precision): array
    {
        $result = [];
        $remainders = [];
        $allocated = BigDecimal::zero();
        foreach ($parts as $key => $amount) {
            $exact = BigDecimal::of($amount);
            $rounded = $exact->toScale($precision, RoundingMode::Floor);
            $result[$key] = (string) $rounded;
            $allocated = $allocated->plus($rounded);
            $remainders[$key] = $exact->minus($rounded);
        }
        $unit = BigDecimal::one()->dividedBy(10 ** $precision, $precision, RoundingMode::Unnecessary);
        $remaining = $target->minus($allocated)->dividedBy($unit, 0, RoundingMode::Unnecessary)->toInt();
        uksort($remainders, static fn ($left, $right): int => $remainders[$right]->compareTo($remainders[$left]) ?: strnatcmp((string) $left, (string) $right));
        $keys = array_keys($remainders);
        if ($keys === [] && $remaining !== 0) {
            throw new \LogicException('spreadsheet_empty_amount_allocation');
        }
        for ($offset = 0; $remaining > 0; $offset++, $remaining--) {
            $key = $keys[$offset % count($keys)];
            $result[$key] = (string) BigDecimal::of($result[$key])->plus($unit);
        }
        if ($remaining < 0) {
            throw new \LogicException('spreadsheet_amount_allocation_invalid');
        }

        return $result;
    }
}
