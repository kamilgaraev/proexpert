<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

final class CanonicalSourceDecimal
{
    private const MAX_ABSOLUTE_VALUE = '1000000000000';

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('~^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$~D', $value) !== 1
            || preg_match('~^-0(?:\.0+)?$~D', $value) === 1) {
            return false;
        }

        $absolute = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');

        return strlen($integer) < strlen(self::MAX_ABSOLUTE_VALUE)
            || (strlen($integer) === strlen(self::MAX_ABSOLUTE_VALUE)
                && ($integer < self::MAX_ABSOLUTE_VALUE
                    || ($integer === self::MAX_ABSOLUTE_VALUE && trim($fraction, '0') === '')));
    }

    public static function isNonNegative(mixed $value): bool
    {
        return self::isValid($value) && ! str_starts_with($value, '-');
    }

    public static function isPositive(mixed $value): bool
    {
        return self::isNonNegative($value) && preg_match('~^0(?:\.0+)?$~D', $value) !== 1;
    }
}
