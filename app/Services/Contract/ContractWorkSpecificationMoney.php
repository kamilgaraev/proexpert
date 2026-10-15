<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;

final class ContractWorkSpecificationMoney
{
    public static function format(string|int $amount, int $scale = 2): string
    {
        [$integer, $fraction] = array_pad(explode('.', FinanceDecimal::value($amount, $scale), 2), 2, '');
        $grouped = preg_replace('/\B(?=(?:\d{3})+$)/', "\u{00A0}", $integer) ?? $integer;

        return $grouped.($fraction === '' ? '' : ','.$fraction);
    }
}
