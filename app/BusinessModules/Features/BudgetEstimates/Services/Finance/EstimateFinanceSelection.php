<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use Illuminate\Validation\ValidationException;

final class EstimateFinanceSelection
{
    public static function rootKeys(array $targets, array $itemIds): array
    {
        $selected = array_fill_keys(array_map(static fn ($id): string => 'i:'.$id, $itemIds), true);
        $keys = [];
        foreach ($selected as $key => $_) {
            $target = $targets[$key] ?? null;
            if ($target === null) {
                throw ValidationException::withMessages(['items' => trans_message('estimate_finance.invalid')]);
            }
            if ($target['excluded']) {
                continue;
            }
            if ($target['parent_key']) {
                if (! isset($selected[$target['parent_key']])) {
                    throw ValidationException::withMessages(['items' => trans_message('estimate_finance.composition')]);
                }
                continue;
            }
            $keys[$key] = true;
        }

        return $keys;
    }

    public static function amount(array $targets, array $itemIds, bool $includeVat = false, ?string $rate = null): string
    {
        $total = '0.00';
        foreach (self::rootKeys($targets, $itemIds) as $key => $_) {
            $tax = EstimateFinanceTax::calculate([
                'vat_mode' => $includeVat ? 'exclusive' : 'none',
                'vat_rate' => $includeVat ? $rate : null,
                'price_basis' => 'without_vat',
            ], FinanceDecimal::value($targets[$key]['estimate_amount']));
            $total = FinanceDecimal::add($total, $tax['amount_with_vat']);
        }

        return $total;
    }
}
