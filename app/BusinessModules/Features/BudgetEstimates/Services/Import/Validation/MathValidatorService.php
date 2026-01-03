<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

class MathValidatorService
{
    private const TOLERANCE = 0.01;

    /**
     * Проверяет математическую корректность строки (Цена * Количество == Сумма)
     * 
     * @param float|null $quantity
     * @param float|null $unitPrice
     * @param float|null $totalAmount
     * @return array Массив с предупреждениями, если есть несоответствия
     */
    public function validateRow(?float $quantity, ?float $unitPrice, ?float $totalAmount): array
    {
        $warnings = [];

        if ($quantity === null || $unitPrice === null || $totalAmount === null) {
            return $warnings; // Недостаточно данных для проверки
        }

        // Если количество или цена 0, сумма должна быть 0
        if (($quantity == 0 || $unitPrice == 0) && abs($totalAmount) > self::TOLERANCE) {
            $warnings[] = sprintf(
                'Математическая ошибка: При кол-ве %.2f и цене %.2f сумма не может быть %.2f',
                $quantity, $unitPrice, $totalAmount
            );
            return $warnings;
        }

        $calculatedTotal = $quantity * $unitPrice;
        $diff = abs($calculatedTotal - $totalAmount);

        if ($diff > self::TOLERANCE) {
            $warnings[] = sprintf(
                'Расхождение в расчетах: %.2f * %.2f = %.2f, но в файле указано %.2f (разница %.2f)',
                $quantity, $unitPrice, $calculatedTotal, $totalAmount, $diff
            );
        }

        return $warnings;
    }

    /**
     * Проверяет корректность применения коэффициентов
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

        // Для коэффициентов допустимая погрешность может быть чуть выше из-за округлений промежуточных
        if ($diff > self::TOLERANCE * 5) { 
             $warnings[] = sprintf(
                'Ошибка индексации: База %.2f * Индекс %.2f = %.2f, но указано %.2f',
                $basePrice, $coefficient, $calculated, $currentPrice
            );
        }

        return $warnings;
    }
}
