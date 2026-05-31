<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

use function trans_message;

class MathValidatorService
{
    private const TOLERANCE = 0.01;

    /**
     * @return array<int, string>
     */
    public function validateRow(?float $quantity, ?float $unitPrice, ?float $totalAmount): array
    {
        $warnings = [];

        if ($quantity === null || $unitPrice === null || $totalAmount === null) {
            return $warnings;
        }

        if (($quantity == 0 || $unitPrice == 0) && abs($totalAmount) > self::TOLERANCE) {
            return [
                trans_message('estimate.import_math_zero_total_mismatch', [
                    'quantity' => $this->formatNumber($quantity),
                    'unit_price' => $this->formatNumber($unitPrice),
                    'total' => $this->formatNumber($totalAmount),
                ]),
            ];
        }

        $calculatedTotal = $quantity * $unitPrice;
        $diff = abs($calculatedTotal - $totalAmount);

        if ($diff > self::TOLERANCE) {
            $warnings[] = trans_message('estimate.import_math_total_mismatch', [
                'quantity' => $this->formatNumber($quantity),
                'unit_price' => $this->formatNumber($unitPrice),
                'calculated' => $this->formatNumber($calculatedTotal),
                'total' => $this->formatNumber($totalAmount),
                'diff' => $this->formatNumber($diff),
            ]);
        }

        return $warnings;
    }

    /**
     * @return array<int, string>
     */
    public function validateCoefficients(?float $basePrice, ?float $coefficient, ?float $currentPrice): array
    {
        $warnings = [];

        if ($basePrice === null || $coefficient === null || $currentPrice === null) {
            return $warnings;
        }

        if ($basePrice == 0 || $coefficient == 0) {
            return $warnings;
        }

        $calculated = $basePrice * $coefficient;
        $diff = abs($calculated - $currentPrice);

        if ($diff > self::TOLERANCE * 5) {
            $warnings[] = trans_message('estimate.import_math_coefficient_mismatch', [
                'base' => $this->formatNumber($basePrice),
                'coefficient' => $this->formatNumber($coefficient),
                'calculated' => $this->formatNumber($calculated),
                'current' => $this->formatNumber($currentPrice),
            ]);
        }

        return $warnings;
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
