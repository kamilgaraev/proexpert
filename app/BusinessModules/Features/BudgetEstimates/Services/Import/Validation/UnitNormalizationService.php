<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

class UnitNormalizationService
{
    private array $normalizationMap = [
        'м3' => 'м³',
        'м2' => 'м²',
        'м.кв' => 'м²',
        'кв.м' => 'м²',
        'м.куб' => 'м³',
        'куб.м' => 'м³',
        'пог.м' => 'м',
        'мп' => 'м',
        'п.м' => 'м',
        'шт.' => 'шт',
        'компл.' => 'компл',
        'т.' => 'т',
        'тн' => 'т',
        'кг.' => 'кг',
        'чел-ч' => 'чел.час',
        'чел/час' => 'чел.час',
        'маш-ч' => 'маш.час',
        'маш/час' => 'маш.час',
    ];

    public function normalize(string $unit): string
    {
        $unit = trim($unit);
        $lowerUnit = mb_strtolower($unit);

        if (isset($this->normalizationMap[$lowerUnit])) {
            return $this->normalizationMap[$lowerUnit];
        }

        // Удаляем точку в конце, если это сокращение
        if (str_ends_with($unit, '.') && mb_strlen($unit) <= 4) {
            $trimmed = rtrim($unit, '.');
            if (isset($this->normalizationMap[mb_strtolower($trimmed)])) {
                return $this->normalizationMap[mb_strtolower($trimmed)];
            }
        }

        return $unit;
    }
}
