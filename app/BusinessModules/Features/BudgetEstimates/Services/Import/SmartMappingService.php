<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\Log;

class SmartMappingService
{
    private array $columnKeywords = [
        'name' => [
            'наименование', 'название', 'работа', 'позиция', 'наименование работ',
            'наименование работ и затрат', 'наименование работ затрат', 'работ и затрат'
        ],
        'unit' => [
            'ед.изм', 'единица', 'ед', 'измерение', 'ед. изм', 'единица измерения', 'ед.изм.'
        ],
        'quantity' => [
            'количество на единицу', 'количество', 'кол-во', 'объем', 'кол', 'объём', 'кол.'
        ],
        'quantity_coefficient' => ['коэффициенты', 'коэф.', 'к-т'],
        'quantity_total' => ['всего с учетом коэффициентов', 'количество всего', 'итого количество'],
        'base_unit_price' => [
            'базисном уровне цен на единицу', 'на единицу измерения в базисном',
            'в базисном уровне', 'базисный уровень'
        ],
        'price_index' => ['индекс', 'индекс пересчета'],
        'current_unit_price' => [
            'текущем уровне цен на единицу', 'на единицу измерения в текущем',
            'в текущем уровне', 'текущий уровень'
        ],
        'price_coefficient' => ['коэффициенты стоимость', 'коэф. стоимость'],
        'current_total_amount' => [
            'всего в текущем уровне', 'всего текущий', 'сметная стоимость всего'
        ],
        'unit_price' => [
            'сметная стоимость', 'цена', 'стоимость', 'расценка', 'цена за ед', 'стоимость единицы'
        ],
        'code' => [
            'код', 'шифр', 'обоснование', 'гэсн', 'фер', 'тер', 'фсбц', 'фсбцс',
            'шифр расценки', 'шифр нормы', 'код нормы', 'нормативы', 'код норматива', 'расценка'
        ],
        'section_number' => ['№', 'номер', '№ п/п', 'п/п', 'n', '№п/п'],
    ];

    public function detectMapping(array $headers): array
    {
        $mapping = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'quantity_coefficient' => null,
            'quantity_total' => null,
            'unit_price' => null,
            'base_unit_price' => null,
            'price_index' => null,
            'current_unit_price' => null,
            'price_coefficient' => null,
            'current_total_amount' => null,
            'code' => null,
        ];
        
        $detectedColumns = [];
        $usedColumns = [];

        foreach ($headers as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim($headerText));
            
            // Find best match for this column
            $bestMatch = null;
            $bestConfidence = 0.0;

            foreach ($this->columnKeywords as $field => $keywords) {
                // If field already mapped with high confidence, skip? No, maybe this column is better.
                
                $confidence = $this->calculateColumnConfidence($normalized, $keywords);
                
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestMatch = $field;
                }
            }

            if ($bestMatch && $bestConfidence > 0.5) {
                // Check if this field is already mapped with better confidence
                // Simplified: Just map if not mapped
                if ($mapping[$bestMatch] === null) {
                    $mapping[$bestMatch] = $columnLetter;
                    $usedColumns[$columnLetter] = $bestMatch;
                }
            }
            
            $detectedColumns[$columnLetter] = [
                'field' => $bestMatch,
                'header' => $headerText,
                'confidence' => $bestConfidence,
                'is_mapped' => isset($usedColumns[$columnLetter]) && $usedColumns[$columnLetter] === $bestMatch
            ];
        }

        return [
            'mapping' => $mapping,
            'detected_columns' => $detectedColumns
        ];
    }

    private function calculateColumnConfidence(string $normalized, array $keywords): float
    {
        if (empty($normalized)) {
            return 0.0;
        }
        
        foreach ($keywords as $keyword) {
            if ($normalized === $keyword) {
                return 1.0;
            }
            
            if (str_contains($normalized, $keyword)) {
                $lengthRatio = mb_strlen($keyword) / max(mb_strlen($normalized), 1);
                $position = mb_strpos($normalized, $keyword);
                $positionBonus = ($position === 0) ? 0.2 : 0;
                
                return min($lengthRatio + $positionBonus, 0.95);
            }
        }
        
        return 0.0;
    }
}
