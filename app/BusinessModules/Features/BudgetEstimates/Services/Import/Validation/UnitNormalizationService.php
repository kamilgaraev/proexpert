<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

class UnitNormalizationService
{
    private array $normalizationMap = [
        // Площадь
        'м2' => 'м²',
        'm2' => 'м²',
        'm²' => 'м²',
        'м^2' => 'м²',
        'м.кв' => 'м²',
        'кв.м' => 'м²',
        'кв.м.' => 'м²',
        '100 м2' => '100 м²',
        '100 кв.м' => '100 м²',
        
        // Объем
        'м3' => 'м³',
        'm3' => 'м³',
        'm³' => 'м³',
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
        '1000 м' => '1000 м',
        
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
        'чел-ч' => 'чел-ч',
        'чел/час' => 'чел-ч',
        'чел.ч' => 'чел-ч',
        'чел.-ч' => 'чел-ч',
        'чел-час' => 'чел-ч',
        'чел.час' => 'чел-ч',
        'чел/ч' => 'чел-ч',
        'ч-ч' => 'чел-ч',
        'маш-ч' => 'маш-ч',
        'маш/час' => 'маш-ч',
        'маш.ч' => 'маш-ч',
        'маш.-ч' => 'маш-ч',
        'маш-час' => 'маш-ч',
        'маш.час' => 'маш-ч',
        'маш/ч' => 'маш-ч',
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
            'челч' => 'чел-ч',
            'чел-ч' => 'чел-ч',
            'чел-час' => 'чел-ч',
            'челчас' => 'чел-ч',
            'машч' => 'маш-ч',
            'маш-ч' => 'маш-ч',
            'маш-час' => 'маш-ч',
            'машчас' => 'маш-ч',
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
            
            if ($normalizedBase) {
                return "{$multiplier} {$normalizedBase}";
            }
        }

        return $original;
    }

    public function normalizeForEstimateImport(?string $unit): ?string
    {
        $lowerUnit = mb_strtolower(trim((string) $unit));
        if (preg_match('/^(?:(\d+)\s*)?пог(?:он)?[.\s]*м\.?$/u', $lowerUnit, $matches) === 1) {
            return isset($matches[1]) && $matches[1] !== '' ? $matches[1].' пог. м' : 'пог. м';
        }

        return $this->normalize($unit);
    }
}
