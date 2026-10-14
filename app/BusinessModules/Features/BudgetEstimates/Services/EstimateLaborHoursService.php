<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\EstimateItem;
use Illuminate\Support\Collection;

class EstimateLaborHoursService
{
    public function calculate(EstimateItem $item, ?Collection $loadedChildren = null): float
    {
        $workersSummary = 0.0;
        $workersDetails = 0.0;
        $operatorsSummary = 0.0;
        $operatorsDetails = 0.0;

        $children = $loadedChildren !== null
            ? $loadedChildren->filter(static fn (EstimateItem $child): bool => (string) $child->estimate_id === (string) $item->estimate_id
                && (string) $child->parent_work_id === (string) $item->id
                && ($child->item_type->value ?? $child->item_type) === 'labor')
            : EstimateItem::query()
                ->where('estimate_id', $item->estimate_id)
                ->where('parent_work_id', $item->id)
                ->where('item_type', 'labor')
                ->get();

        foreach ($children as $child) {
            $imported = $child->is_manual && array_key_exists('raw_data', $child->metadata ?? []);
            if ($child->is_not_accounted && ! $imported) {
                continue;
            }

            $hours = (float) $child->labor_hours;
            if ($hours <= 0 && $imported) {
                $hours = (float) $child->quantity;
            }
            $hours = max(0, $hours);
            $code = trim($child->normative_rate_code ?? '');
            $name = mb_strtolower(trim($child->name));
            $operator = str_starts_with($code, '4-100-')
                || str_contains($name, 'отм') || str_contains($name, 'зтм');
            $detail = str_starts_with($code, '1-100-') || str_starts_with($code, '4-100-')
                || str_contains($name, 'средний разряд');

            if ($operator && $detail) {
                $operatorsDetails += $hours;
            } elseif ($operator) {
                $operatorsSummary += $hours;
            } elseif ($detail) {
                $workersDetails += $hours;
            } else {
                $workersSummary += $hours;
            }
        }

        return max($workersSummary, $workersDetails) + max($operatorsSummary, $operatorsDetails);
    }
}
