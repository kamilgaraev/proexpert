<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\Log;

class SmartMappingService
{
    private array $columnKeywords = [
        'name' => [
            'наименование', 'название', 'работы и затраты', 'описание',
            'наименование работ затрат', 'работ и затрат',
            'наименование работ и затрат, единица измерения'
        ],
        'unit' => [
            'ед.изм', 'единица', 'ед', 'измерение', 'ед. изм', 'единица измерения', 'ед.изм.',
            'наименование работ и затрат, единица измерения'
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
            'всего в текущем уровне', 'всего текущий', 'сметная стоимость всего',
            'общая стоимость', 'всего, руб.', 'итого по позиции', 'всего по позиции',
            'общая стоимость, руб.'
        ],
        'unit_price' => [
            'сметная стоимость', 'цена', 'стоимость', 'цена за ед', 'стоимость единицы',
            'стоимость единицы, руб.', 'цена за единицу'
        ],
        'code' => [
            'код', 'шифр', 'обоснование', 'гэсн', 'фер', 'тер', 'фсбц', 'фсбцс',
            'шифр расценки', 'шифр нормы', 'код нормы', 'нормативы', 'код норматива',
            'шифр и номер позиции норматива'
        ],
        'section_number' => ['№ п/п', 'п/п', '№п/п', '№ пп', '№пп'],
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

        foreach ($headers as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim((string)$headerText));
            if (empty($normalized)) continue;

            $bestMatch = null;
            $bestConfidence = 0.0;

            foreach ($this->columnKeywords as $field => $keywords) {
                $confidence = $this->calculateColumnConfidence($normalized, $keywords);
                
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestMatch = $field;
                }

                // Auto-map if high confidence and not yet mapped
                if ($confidence > 0.45 && $mapping[$field] === null) {
                    $mapping[$field] = $columnLetter;
                }
            }
            
            $detectedColumns[$columnLetter] = [
                'field' => $bestMatch,
                'header' => $headerText,
                'confidence' => $bestConfidence,
                'is_mapped' => in_array($columnLetter, $mapping)
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
            $kwNormalized = mb_strtolower($keyword);
            
            // Exact match
            if ($normalized === $kwNormalized) {
                return 1.0;
            }
            
            // Whole word match (important for "наименование ... единица измерения")
            if (preg_match('/\b' . preg_quote($kwNormalized, '/') . '\b/u', $normalized)) {
                $lengthRatio = mb_strlen($kwNormalized) / mb_strlen($normalized);
                return min(0.6 + ($lengthRatio * 0.35), 0.95);
            }

            // Substring match
            if (str_contains($normalized, $kwNormalized)) {
                return 0.5;
            }
        }
        
        return 0.0;
    }
}
