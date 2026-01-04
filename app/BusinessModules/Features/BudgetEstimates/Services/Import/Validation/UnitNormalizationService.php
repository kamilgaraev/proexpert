<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

class UnitNormalizationService
{
    private array $normalizationMap = [
        // Площадь
        'м2' => 'м²',
        'м^2' => 'м²',
        'м.кв' => 'м²',
        'кв.м' => 'м²',
        'кв.м.' => 'м²',
        '100 м2' => '100 м²',
        '100 кв.м' => '100 м²',
        
        // Объем
        'м3' => 'м³',
        'м^3' => 'м³',
        'м.куб' => 'м³',
        'куб.м' => 'м³',
        'куб.м.' => 'м³',
        '100 м3' => '100 м³',
        
        // Длина
        'м.' => 'м',
        'пог.м' => 'м',
        'п.м' => 'м',
        'мп' => 'м',
        'п.м.' => 'м',
        'км' => 'км',
        '100 м' => '100 м',
        '1000 м' => '1 км',
        
        // Вес
        'т.' => 'т',
        'тн' => 'т',
        'тн.' => 'т',
        'кг.' => 'кг',
        
        // Штучные
        'шт.' => 'шт',
        'шт' => 'шт',
        '10 шт' => '10 шт',
        '100 шт' => '100 шт',
        'компл.' => 'компл',
        'комплект' => 'компл',
        'упак.' => 'упак',
        'упаковка' => 'упак',
        
        // Трудозатраты
        'чел-ч' => 'чел.час',
        'чел/час' => 'чел.час',
        'чел.ч' => 'чел.час',
        'маш-ч' => 'маш.час',
        'маш/час' => 'маш.час',
        'маш.ч' => 'маш.час',
    ];

    public function normalize(?string $unit): ?string
    {
        if (empty($unit)) {
            return null;
        }

        $original = trim($unit);
        $lowerUnit = mb_strtolower($original);

        // 1. Прямое совпадение
        if (isset($this->normalizationMap[$lowerUnit])) {
            return $this->normalizationMap[$lowerUnit];
        }

        // 2. Очистка от лишних символов (пробелы, точки) для поиска
        $cleanUnit = preg_replace('/[\s\.]+/u', '', $lowerUnit);
        
        // Кастомные правила для "чистых" строк
        $mapClean = [
            'м2' => 'м²',
            'м3' => 'м³',
            'квм' => 'м²',
            'кубм' => 'м³',
            'пм' => 'м',
            'шт' => 'шт',
            'кг' => 'кг',
            'т' => 'т',
            'тн' => 'т',
        ];
        
        if (isset($mapClean[$cleanUnit])) {
            return $mapClean[$cleanUnit];
        }

        // 3. Обработка "100 м2" и подобных (множители)
        if (preg_match('/^(\d+)\s*(.*)$/u', $lowerUnit, $matches)) {
            $multiplier = $matches[1];
            $baseUnit = trim($matches[2]);
            
            // Рекурсивно нормализуем базовую часть
            $normalizedBase = $this->normalize($baseUnit);
            
            if ($normalizedBase && $normalizedBase !== $baseUnit) {
                return "{$multiplier} {$normalizedBase}";
            }
        }

        return $original;
    }
}
