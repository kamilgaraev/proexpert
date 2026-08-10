<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final class DecimalValue
{
    public static function canonical(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Decimal value is invalid.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        if (strlen($integer) > 20 || strlen($fraction) > 12) {
            throw new InvalidArgumentException('Decimal value exceeds numeric(32,12).');
        }
        $canonical = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $canonical !== '0' ? '-'.$canonical : $canonical;
    }
}
