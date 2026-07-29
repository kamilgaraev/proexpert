<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PortfolioDecimal
{
    public static function money(string|int $value): string
    {
        try {
            return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }

    public static function add(string ...$values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            try {
                $sum = $sum->plus($value);
            } catch (MathException) {
                throw new InvalidArgumentException('portfolio_money_invalid');
            }
        }

        return (string) $sum->toScale(2, RoundingMode::HalfUp);
    }

    public static function subtract(string $left, string $right): string
    {
        try {
            return (string) BigDecimal::of($left)
                ->minus($right)
                ->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }

    public static function percent(string $numerator, string $denominator): ?string
    {
        try {
            $denominatorDecimal = BigDecimal::of($denominator);
            if ($denominatorDecimal->isZero()) {
                return null;
            }

            return (string) BigDecimal::of($numerator)
                ->multipliedBy(100)
                ->dividedBy($denominatorDecimal, 8, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }

    public static function isNegative(string $value): bool
    {
        try {
            return BigDecimal::of($value)->isNegative();
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }

    public static function compare(string $left, string $right): int
    {
        try {
            return BigDecimal::of($left)->compareTo($right);
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }

    public static function cashGapRiskPoints(string $value): string
    {
        try {
            $points = BigDecimal::of($value)->abs()->dividedBy(1000, 2, RoundingMode::HalfUp);
            if ($points->isGreaterThan(100)) {
                $points = BigDecimal::of(100);
            }

            return (string) $points->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new InvalidArgumentException('portfolio_money_invalid');
        }
    }
}
