<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use DomainException;

final readonly class ExactDecimal
{
    public static function minor(string $value): int
    {
        return self::units($value, 2);
    }

    public static function multiplyMinor(int $minor, string $multiplier, int $scale = 2): int
    {
        $units = self::units($multiplier, $scale);
        $factor = 10 ** $scale;
        if ($units !== 0 && abs($minor) > intdiv(PHP_INT_MAX, abs($units))) {
            throw new DomainException('report_decimal_overflow');
        }

        $product = $minor * $units;
        $absolute = abs($product);
        $quotient = intdiv($absolute, $factor);
        $remainder = $absolute % $factor;
        if ($remainder * 2 >= $factor) {
            $quotient++;
        }

        return $product < 0 ? -$quotient : $quotient;
    }

    public static function units(string $value, int $scale): int
    {
        if ($scale < 0 || $scale > 9
            || preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', trim($value), $matches) !== 1
            || strlen($matches[3] ?? '') > $scale) {
            throw new DomainException('report_decimal_invalid');
        }

        $factor = 10 ** $scale;
        $whole = (int) $matches[2];
        if ($whole > intdiv(PHP_INT_MAX, $factor)) {
            throw new DomainException('report_decimal_overflow');
        }
        $fraction = (int) str_pad($matches[3] ?? '', $scale, '0');
        $units = ($whole * $factor) + $fraction;

        return $matches[1] === '-' ? -$units : $units;
    }
}
