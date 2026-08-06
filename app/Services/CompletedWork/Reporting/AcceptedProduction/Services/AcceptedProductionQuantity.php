<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use InvalidArgumentException;

final class AcceptedProductionQuantity
{
    public const FACTOR = 10_000;

    private const SCALE = 4;

    private const MIN_OUTPUT_SCALE = 3;

    private function __construct() {}

    public static function scaled(string $value, string $error): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $unsigned = ($negative || str_starts_with($value, '+'))
            ? substr($value, 1)
            : $value;
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $unsigned, $matches) !== 1) {
            throw new InvalidArgumentException($error);
        }

        $scaled = ((int) $matches[1] * self::FACTOR)
            + (int) str_pad($matches[2] ?? '', self::SCALE, '0');

        return $negative ? -$scaled : $scaled;
    }

    public static function decimal(int $scaled): string
    {
        $absolute = abs($scaled);
        $fraction = str_pad(
            (string) ($absolute % self::FACTOR),
            self::SCALE,
            '0',
            STR_PAD_LEFT,
        );
        while (strlen($fraction) > self::MIN_OUTPUT_SCALE && str_ends_with($fraction, '0')) {
            $fraction = substr($fraction, 0, -1);
        }
        $value = intdiv($absolute, self::FACTOR).'.'.$fraction;

        return $scaled < 0 ? '-'.$value : $value;
    }

    public static function normalize(string $value, string $error): string
    {
        return self::decimal(self::scaled($value, $error));
    }

    public static function multiplyRateMinor(int $quantityScaled, int $rateMinor, string $error): int
    {
        $negative = ($quantityScaled < 0) !== ($rateMinor < 0);
        $quantity = abs($quantityScaled);
        $rate = abs($rateMinor);
        $wholeQuantity = intdiv($quantity, self::FACTOR);
        $fractionQuantity = $quantity % self::FACTOR;
        if ($wholeQuantity > 0 && $rate > intdiv(PHP_INT_MAX, $wholeQuantity)) {
            throw new InvalidArgumentException($error);
        }
        $amount = $wholeQuantity * $rate;
        $rateWhole = intdiv($rate, self::FACTOR);
        if ($fractionQuantity > 0
            && $rateWhole > intdiv(PHP_INT_MAX - $amount, $fractionQuantity)
        ) {
            throw new InvalidArgumentException($error);
        }
        $amount += $fractionQuantity * $rateWhole;
        $fractionAmount = intdiv(
            ($fractionQuantity * ($rate % self::FACTOR)) + intdiv(self::FACTOR, 2),
            self::FACTOR,
        );
        if ($amount > PHP_INT_MAX - $fractionAmount) {
            throw new InvalidArgumentException($error);
        }
        $amount += $fractionAmount;

        return $negative ? -$amount : $amount;
    }
}
