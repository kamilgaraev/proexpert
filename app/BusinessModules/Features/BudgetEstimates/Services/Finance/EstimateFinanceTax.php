<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use Illuminate\Validation\ValidationException;

final class EstimateFinanceTax
{
    public static function mode(array $line): string
    {
        return $line['vat_mode'] ?? (isset($line['vat_rate']) ? match ($line['price_basis']) {
            'with_vat' => 'included',
            'without_vat' => 'exclusive',
            default => 'unknown',
        } : 'unknown');
    }

    public static function calculate(array $line, ?string $amount): array
    {
        $mode = self::mode($line);
        $basis = $line['price_basis'];
        $rate = $line['vat_rate'] ?? null;
        if (($mode === 'none' && ($rate !== null || $basis !== 'without_vat'))
            || ($mode === 'included' && ($rate === null || $basis !== 'with_vat'))
            || ($mode === 'exclusive' && ($rate === null || $basis !== 'without_vat'))
            || (isset($line['vat_mode']) && $mode === 'unknown' && ($rate !== null || $basis !== 'unknown'))) {
            throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.tax_conditions')]);
        }
        $net = $basis === 'without_vat' ? $amount : null;
        $gross = $basis === 'with_vat' ? $amount : null;
        if ($mode === 'none') {
            $net = $gross = $amount;
        } elseif ($amount !== null && $rate !== null && $basis !== 'unknown') {
            $factor = FinanceDecimal::add('1', FinanceDecimal::divide($rate, '100', 8));
            $net ??= FinanceDecimal::divide($amount, $factor);
            $gross ??= FinanceDecimal::multiply($amount, $factor);
        }

        return ['vat_mode' => $mode, 'amount_without_vat' => $net, 'amount_with_vat' => $gross];
    }
}
