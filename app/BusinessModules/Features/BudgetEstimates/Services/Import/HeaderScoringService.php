<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class HeaderScoringService
{
    /**
     * Вычисляет взвешенный score для кандидата на роль заголовка
     *
     * @param array $candidate
     * @param array $context Дополнительный контекст (worksheet, другие кандидаты и т.д.)
     * @return float Score от 0.0 до 1.0
     */
    public function calculateScore(array $candidate, array $context = []): float
    {
        $scores = [
            'real_columns' => $this->scoreRealColumns($candidate) * 0.40, // 40%
            'keyword_matching' => $this->scoreKeywordMatching($candidate) * 0.25, // 25%
            'structural_validity' => $this->scoreStructuralValidity($candidate, $context) * 0.20, // 20%
            'position' => $this->scorePosition($candidate) * 0.10, // 10%
            'merge_penalty' => $this->scoreMergeCells($candidate) * 0.05, // 5%
        ];

        $totalScore = array_sum($scores);

        Log::debug('[HeaderScoring] Score breakdown', [
            'row' => $candidate['row'],
            'scores' => array_map(fn($s) => round($s, 3), $scores),
            'total' => round($totalScore, 3),
        ]);

        return min($totalScore, 1.0);
    }

    /**
     * Возвращает детальное объяснение score
     *
     * @param array $candidate
     * @param array $context
     * @return array
     */
    public function explainScore(array $candidate, array $context = []): array
    {
        return [
            'row' => $candidate['row'],
            'total_score' => $this->calculateScore($candidate, $context),
            'breakdown' => [
                'real_columns' => [
                    'score' => $this->scoreRealColumns($candidate),
                    'weight' => 0.40,
                    'weighted' => $this->scoreRealColumns($candidate) * 0.40,
                    'description' => 'Количество реальных заполненных колонок',
                    'value' => $candidate['real_filled_count'] ?? $candidate['filled_columns'] ?? 0,
                ],
                'keyword_matching' => [
                    'score' => $this->scoreKeywordMatching($candidate),
                    'weight' => 0.25,
                    'weighted' => $this->scoreKeywordMatching($candidate) * 0.25,
                    'description' => 'Совпадение с известными терминами',
                    'matches' => $candidate['keyword_matches'] ?? $candidate['matched_keywords'] ?? [],
                ],
                'structural_validity' => [
                    'score' => $this->scoreStructuralValidity($candidate, $context),
                    'weight' => 0.20,
                    'weighted' => $this->scoreStructuralValidity($candidate, $context) * 0.20,
                    'description' => 'Наличие данных после заголовка',
                ],
                'position' => [
                    'score' => $this->scorePosition($candidate),
                    'weight' => 0.10,
                    'weighted' => $this->scorePosition($candidate) * 0.10,
                    'description' => 'Позиция в файле (оптимально 20-40)',
                    'value' => $candidate['row'],
                ],
                'merge_cells' => [
                    'score' => $this->scoreMergeCells($candidate),
                    'weight' => 0.05,
                    'weighted' => $this->scoreMergeCells($candidate) * 0.05,
                    'description' => 'Отсутствие объединенных ячеек (предпочтительно)',
                    'has_merged' => $candidate['has_merged_cells'] ?? false,
                ],
            ],
        ];
    }

    /**
     * Оценка на основе количества реальных колонок
     */
    private function scoreRealColumns(array $candidate): float
    {
        $realFilled = $candidate['real_filled_count'] ?? $candidate['filled_columns'] ?? 0;
        
        // Оптимум: 8-15 колонок = 1.0
        if ($realFilled >= 8 && $realFilled <= 15) {
            return 1.0;
        }
        
        // Приемлемо: 5-7 или 16-20 колонок = 0.7
        if (($realFilled >= 5 && $realFilled < 8) || ($realFilled >= 16 && $realFilled <= 20)) {
            return 0.7;
        }
        
        // Минимум: 3-4 колонки = 0.4
        if ($realFilled >= 3 && $realFilled < 5) {
            return 0.4;
        }
        
        // Слишком мало: < 3 колонки = 0.0
        if ($realFilled < 3) {
            return 0.0;
        }
        
        // Слишком много: > 20 колонок = 0.3
        return 0.3;
    }

    /**
     * Оценка на основе keyword matching
     */
    private function scoreKeywordMatching(array $candidate): float
    {
        $matchCount = $candidate['keyword_matches'] ?? 0;
        $uniqueKeywords = $candidate['unique_keywords'] ?? [];
        $uniqueCount = is_array($uniqueKeywords) ? count($uniqueKeywords) : 0;
        
        // Много уникальных keywords = отлично
        if ($uniqueCount >= 6) {
            return 1.0;
        }
        
        if ($uniqueCount >= 4) {
            return 0.8;
        }
        
        if ($uniqueCount >= 3) {
            return 0.6;
        }
        
        if ($uniqueCount >= 2) {
            return 0.4;
        }
        
        if ($uniqueCount >= 1) {
            return 0.2;
        }
        
        return 0.0;
    }

    /**
     * Оценка структурной валидности (наличие данных после заголовка)
     */
    private function scoreStructuralValidity(array $candidate, array $context): float
    {
        // Если есть worksheet в контексте, можем проверить данные после заголовка
        if (isset($context['sheet']) && $context['sheet'] instanceof Worksheet) {
            $sheet = $context['sheet'];
            $headerRow = $candidate['row'];
            
            $hasDataAfter = $this->checkDataAfterHeader($sheet, $headerRow);
            
            return $hasDataAfter ? 1.0 : 0.0;
        }
        
        // Если контекста нет, проверяем по другим признакам
        // Если в кандидате есть информация о данных - используем её
        if (isset($candidate['has_data_after'])) {
            return $candidate['has_data_after'] ? 1.0 : 0.0;
        }
        
        // По умолчанию нейтрально
        return 0.5;
    }

    /**
     * Оценка позиции в файле
     */
    private function scorePosition(array $candidate): float
    {
        $row = $candidate['row'];
        
        // Оптимальная зона: 20-40
        if ($row >= 20 && $row <= 40) {
            return 1.0;
        }
        
        // Приемлемая зона: 15-19 или 41-50
        if (($row >= 15 && $row < 20) || ($row >= 41 && $row <= 50)) {
            return 0.6;
        }
        
        // Ранняя позиция: 10-14
        if ($row >= 10 && $row < 15) {
            return 0.3;
        }
        
        // Слишком рано: < 10
        if ($row < 10) {
            return 0.0;
        }
        
        // Слишком поздно: > 50
        return 0.2;
    }

    /**
     * Оценка объединенных ячеек (штраф)
     */
    private function scoreMergeCells(array $candidate): float
    {
        $hasMerged = $candidate['has_merged_cells'] ?? false;
        
        // Нет merged cells = хорошо
        if (!$hasMerged) {
            return 1.0;
        }
        
        // Есть merged cells = небольшой штраф
        $mergedCount = $candidate['merged_cells'] ?? 0;
        
        if ($mergedCount <= 2) {
            return 0.8;
        }
        
        if ($mergedCount <= 5) {
            return 0.5;
        }
        
        return 0.2;
    }

    /**
     * Проверяет наличие данных после потенциального заголовка
     */
    private function checkDataAfterHeader(Worksheet $sheet, int $headerRow): bool
    {
        $checkRows = min(10, $sheet->getHighestRow() - $headerRow);
        
        if ($checkRows < 2) {
            return false;
        }
        
        $dataRowsFound = 0;
        $highestCol = $sheet->getHighestColumn();
        
        for ($i = 1; $i <= $checkRows; $i++) {
            $currentRow = $headerRow + $i;
            $hasNumericData = false;
            $hasTextData = false;
            $cellsWithData = 0;
            
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
            
            for ($colIdx = 1; $colIdx <= $highestColIndex; $colIdx++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $value = $sheet->getCell($col . $currentRow)->getValue();
                
                if ($value === null || trim((string)$value) === '') {
                    continue;
                }
                
                $cellsWithData++;
                
                if (is_numeric($value)) {
                    $hasNumericData = true;
                } else {
                    $hasTextData = true;
                }
            }
            
            // Строка с данными (текст + числа)
            if ($hasNumericData && $hasTextData && $cellsWithData >= 2) {
                $dataRowsFound++;
            }
        }
        
        return $dataRowsFound >= 1;
    }
}

