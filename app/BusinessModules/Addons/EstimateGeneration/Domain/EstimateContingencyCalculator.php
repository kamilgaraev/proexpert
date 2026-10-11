<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class EstimateContingencyCalculator
{
    public function amount(array $draft, string $baseTotal): string
    {
        if (array_key_exists('contingency_amount', $draft)
            && (($draft['contingency_kind'] ?? null) === 'fixed' || ! array_key_exists('contingency_percent', $draft))) {
            return OrdinaryEstimateDecimal::money($draft['contingency_amount']);
        }
        $percent = $draft['contingency_percent'] ?? '0';
        if ($percent === null) {
            $percent = '0';
        }
        if ((! is_string($percent) && ! is_int($percent) && ! is_float($percent)) || ! is_numeric($percent)) {
            throw new InvalidArgumentException('estimate_contingency_percent_invalid');
        }
        $percent = BigDecimal::of((string) $percent);
        if ($percent->isLessThan(0) || $percent->isGreaterThan(100)) {
            throw new InvalidArgumentException('estimate_contingency_percent_invalid');
        }

        return OrdinaryEstimateDecimal::money(BigDecimal::of($baseTotal)->multipliedBy($percent)->dividedBy('100', 2, RoundingMode::HalfUp));
    }
}
