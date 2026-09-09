<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class FinanceDecimal
{
    public static function value(string|int $value, int $scale = 2): string
    {
        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function add(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->plus($right);
    }

    public static function subtract(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->minus($right);
    }

    public static function multiply(string $left, string $right, int $scale = 2): string
    {
        return (string) BigDecimal::of($left)->multipliedBy($right)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function divide(string $left, string $right, int $scale = 2): string
    {
        return (string) BigDecimal::of($left)->dividedBy($right, $scale, RoundingMode::HalfUp);
    }

    public static function compare(string $left, string $right): int
    {
        return BigDecimal::of($left)->compareTo($right);
    }

    public static function allocate(string $total, array $weights): array
    {
        $total = BigDecimal::of($total)->toScale(2);
        $sum = BigDecimal::of('0');
        ksort($weights, SORT_STRING);
        foreach ($weights as $weight) {
            if (BigDecimal::of($weight)->isNegative()) {
                throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.invalid')]);
            }
            $sum = $sum->plus($weight);
        }
        if ($sum->isZero() || $total->isNegative()) {
            throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.zero_basis')]);
        }
        $result = [];
        $allocated = BigDecimal::of('0');
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $numerator = $total->multipliedBy($weight);
            $part = $numerator->dividedBy($sum, 2, RoundingMode::Down);
            $result[$key] = (string) $part;
            $allocated = $allocated->plus($part);
            $remainders[$key] = $numerator->minus($part->multipliedBy($sum));
        }
        uksort($remainders, static fn ($a, $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: strcmp((string) $a, (string) $b));
        $pennies = $total->minus($allocated)->multipliedBy(100)->toInt();
        foreach (array_keys($remainders) as $key) {
            if ($pennies-- <= 0) {
                break;
            }
            $result[$key] = (string) BigDecimal::of($result[$key])->plus('0.01');
        }

        return $result;
    }
}
