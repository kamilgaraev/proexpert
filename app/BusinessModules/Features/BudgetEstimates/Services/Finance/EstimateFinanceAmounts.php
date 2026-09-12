<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceAmounts
{
    public static function sum(iterable $rows, string $field): ?float
    {
        $total = '0';
        foreach ($rows as $row) {
            $amount = data_get($row, $field);
            if ($amount === null) {
                return null;
            }
            $total = FinanceDecimal::add($total, (string) $amount);
        }

        return round((float) $total, 2);
    }
}
