<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ImportedEstimateChargeDistributor
{
    public function distribute(array $items, array $footer): array
    {
        if ($items === []) {
            return [];
        }

        foreach (['overhead_cost' => 'overhead_amount', 'profit_cost' => 'profit_amount'] as $source => $component) {
            $incremental = array_key_exists('global_'.$source, $footer);
            if (! array_key_exists($source, $footer) && ! $incremental) {
                continue;
            }

            $allocated = BigDecimal::zero();
            $eligible = [];
            foreach ($items as $id => $item) {
                $hasSourceComponent = $item['has_source_'.$component] ?? false;
                if ($hasSourceComponent && ! $incremental) {
                    $allocated = $allocated->plus((string) ($item[$component] ?? 0));
                } else {
                    $eligible[$id] = BigDecimal::of((string) ($item['labor_cost'] ?? 0))->abs();
                }
            }
            if ($eligible === []) {
                continue;
            }

            $remaining = ($incremental ? BigDecimal::of((string) $footer['global_'.$source])
                : BigDecimal::of((string) $footer[$source])->minus($allocated))->toScale(2, RoundingMode::HalfUp);
            if ($remaining->isZero() && ! $incremental && empty($footer[$source.'_declared'])) {
                continue;
            }

            $weightTotal = BigDecimal::sum(...array_values($eligible));
            if ($weightTotal->isZero()) {
                foreach ($eligible as $id => $weight) {
                    $eligible[$id] = BigDecimal::of((string) ($items[$id]['direct_costs'] ?? 0))->abs();
                }
                $weightTotal = BigDecimal::sum(...array_values($eligible));
            }
            if ($weightTotal->isZero()) {
                $eligible = array_fill_keys(array_keys($eligible), BigDecimal::one());
                $weightTotal = BigDecimal::of(count($eligible));
            }

            $amount = $remaining;
            $lastId = array_key_last($eligible);
            foreach ($eligible as $id => $weight) {
                $share = $id === $lastId ? $remaining : $amount->multipliedBy($weight)->dividedBy($weightTotal, 2, RoundingMode::HalfUp);
                $previous = BigDecimal::of((string) ($items[$id][$component] ?? 0));
                $target = $incremental && ($items[$id]['has_source_'.$component] ?? false)
                    ? $previous->plus($share) : $share;
                $items[$id][$component] = (string) $target;
                $items[$id]['footer_components'][$component] = (string) $share;
                $items[$id]['current_total_amount'] = (string) BigDecimal::of((string) $items[$id]['current_total_amount'])->minus($previous)->plus($target)->toScale(2, RoundingMode::HalfUp);
                $remaining = $remaining->minus($share);
            }
        }

        return $items;
    }
}
