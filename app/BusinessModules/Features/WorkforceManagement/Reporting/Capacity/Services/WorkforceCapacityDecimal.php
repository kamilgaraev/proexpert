<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use InvalidArgumentException;

final class WorkforceCapacityDecimal
{
    public static function parse(mixed $value, int $scale): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('workforce_capacity_decimal_invalid');
        }

        $text = (string) $value;
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $matches) !== 1) {
            throw new InvalidArgumentException('workforce_capacity_decimal_invalid');
        }

        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException('workforce_capacity_decimal_scale_invalid');
        }

        $integer = (int) $matches[2];
        $scaled = $integer * (10 ** $scale) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');

        return $matches[1] === '-' ? -$scaled : $scaled;
    }

    public static function format(int $value, int $scale): string
    {
        $factor = 10 ** $scale;
        $absolute = abs($value);
        $sign = $value < 0 ? '-' : '';

        return $sign.intdiv($absolute, $factor).'.'.str_pad((string) ($absolute % $factor), $scale, '0', STR_PAD_LEFT);
    }

    public static function multiply(int $left, int $leftScale, int $right, int $rightScale, int $resultScale): int
    {
        $denominator = 10 ** ($leftScale + $rightScale - $resultScale);
        $product = $left * $right;
        $half = intdiv($denominator, 2);

        return $product >= 0
            ? intdiv($product + $half, $denominator)
            : intdiv($product - $half, $denominator);
    }
}
