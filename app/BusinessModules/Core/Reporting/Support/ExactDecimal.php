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

    public static function percentage(int $numerator, int $denominator, int $scale = 8): string
    {
        if ($denominator === 0 || $denominator === PHP_INT_MIN || $scale < 0 || $scale > 9) {
            throw new DomainException('report_decimal_invalid');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $numeratorDigits = ltrim((string) $numerator, '-');
        $denominatorValue = (int) abs($denominator);
        $scaled = $numeratorDigits.'00'.str_repeat('0', $scale);
        [$quotient, $remainder] = self::divideDigits($scaled, $denominatorValue);
        if ($remainder >= self::ceilHalf($denominatorValue)) {
            $quotient = self::incrementDigits($quotient);
        }
        $quotient = str_pad($quotient, $scale + 1, '0', STR_PAD_LEFT);
        $whole = $scale === 0 ? $quotient : substr($quotient, 0, -$scale);
        $fraction = $scale === 0 ? '' : '.'.substr($quotient, -$scale);
        $value = ltrim($whole, '0');
        $value = ($value === '' ? '0' : $value).$fraction;

        return $negative && trim($value, '0.') !== '' ? '-'.$value : $value;
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

    private static function divideDigits(string $digits, int $denominator): array
    {
        $quotient = '';
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $digitValue = (int) $digit;
            $quotientDigit = self::quotientDigit($remainder, $digitValue, $denominator);
            $remainder = self::nextRemainder($remainder, $digitValue, $denominator, $quotientDigit);
            if ($quotient !== '' || $quotientDigit !== 0) {
                $quotient .= (string) $quotientDigit;
            }
        }

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }

    private static function quotientDigit(int $remainder, int $digit, int $denominator): int
    {
        for ($candidate = 9; $candidate > 0; $candidate--) {
            $threshold = self::ceilTenthProduct($candidate, $denominator, $digit);
            if ($remainder >= $threshold) {
                return $candidate;
            }
        }

        return 0;
    }

    private static function ceilTenthProduct(int $multiplier, int $value, int $digit): int
    {
        $tens = intdiv($value, 10);
        $units = $value % 10;
        $adjusted = ($multiplier * $units) - $digit;

        return ($multiplier * $tens) + ($adjusted <= 0 ? 0 : intdiv($adjusted + 9, 10));
    }

    private static function nextRemainder(
        int $remainder,
        int $digit,
        int $denominator,
        int $quotientDigit,
    ): int {
        $tens = intdiv($denominator, 10);
        $units = $denominator % 10;
        $base = $remainder - ($quotientDigit * $tens);

        return ($base * 10) + $digit - ($quotientDigit * $units);
    }

    private static function ceilHalf(int $value): int
    {
        return intdiv($value, 2) + ($value % 2);
    }

    private static function incrementDigits(string $digits): string
    {
        $characters = str_split($digits);
        for ($index = count($characters) - 1; $index >= 0; $index--) {
            if ($characters[$index] !== '9') {
                $characters[$index] = (string) ((int) $characters[$index] + 1);

                return implode('', $characters);
            }
            $characters[$index] = '0';
        }

        return '1'.implode('', $characters);
    }
}
