<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

final class SpreadsheetNumberParser
{
    public function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $lines = preg_split('/\R/u', trim($value)) ?: [];
        $value = trim($lines[0] ?? '');
        $value = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' ', '−'], ['', '', '', '-'], $value);
        $value = preg_replace('/(?:руб\.?|₽|р\.)$/iu', '', $value) ?? '';
        if (preg_match('/^[+-]?\d[\d,.]*$/D', $value) !== 1) {
            return null;
        }

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif (preg_match('/^[+-]?\d{1,3}(?:[.,]\d{3}){2,}$/D', $value) === 1) {
            $value = str_replace([',', '.'], '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
