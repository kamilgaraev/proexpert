<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\AbstractHeaderDetector;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class KeywordBasedDetector extends AbstractHeaderDetector
{
    private array $columnKeywords;

    public function __construct(array $columnKeywords)
    {
        $this->columnKeywords = $columnKeywords;
        $this->weight = 1.0;
    }

    public function getName(): string
    {
        return 'keyword_based';
    }

    public function detectCandidates(Worksheet $sheet): array
    {
        $candidates = [];
        $maxRow = min($sheet->getHighestRow(), $this->maxRowsToScan);

        for ($row = 1; $row <= $maxRow; $row++) {
            $rowValues = $this->getRowValues($sheet, $row);

            if (empty($rowValues) || $this->isServiceInfo($rowValues)) {
                continue;
            }

            $matches = $this->countKeywordMatches($rowValues);

            // Минимум 3 совпадения для кандидата
            if ($matches['total'] >= 3) {
                $candidates[] = [
                    'row' => $row,
                    'detector' => $this->getName(),
                    'keyword_matches' => $matches['total'],
                    'unique_keywords' => $matches['unique'],
                    'matched_keywords' => $matches['keywords'],
                    'filled_columns' => count($rowValues),
                    'raw_values' => $rowValues,
                ];
            }
        }

        return $candidates;
    }

    public function scoreCandidate(array $candidate, array $context = []): float
    {
        $score = 0.0;

        // 1. Базовый балл за keyword matches (0-0.4)
        $keywordMatches = $candidate['keyword_matches'] ?? 0;
        $score += min($keywordMatches / 10, 0.4);

        // 2. Бонус за уникальность keywords (0-0.3)
        $uniqueKeywords = count($candidate['unique_keywords'] ?? []);
        $score += min($uniqueKeywords / 10, 0.3);

        // 3. Бонус за количество колонок (0-0.2)
        $filledColumns = $candidate['filled_columns'] ?? 0;
        $score += min($filledColumns / 20, 0.2);

        // 4. Небольшой бонус за разумную позицию (0-0.1)
        $row = $candidate['row'] ?? 0;
        if ($row >= 5 && $row <= 50) {
            $score += 0.05; // Заголовки обычно в пределах первых 50 строк
        }
        
        // 5. КРИТИЧНО: Проверка на "заголовочность" vs "данные" (0 to -0.5)
        $isLikelyData = $this->isLikelyDataRow($candidate['raw_values'] ?? []);
        if ($isLikelyData) {
            $score -= 0.5; // Большой штраф если похоже на данные, а не заголовки
        }

        return min($score, 1.0);
    }

    /**
     * Подсчитывает совпадения с ключевыми словами
     *
     * @param array $rowValues
     * @return array
     */
    private function countKeywordMatches(array $rowValues): array
    {
        $totalMatches = 0;
        $uniqueKeywords = [];
        $matchedKeywords = [];

        foreach ($rowValues as $col => $value) {
            $normalized = mb_strtolower($value);

            foreach ($this->columnKeywords as $field => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $keyword)) {
                        $totalMatches++;
                        $matchedKeywords[] = "$col:$keyword";
                        
                        if (!in_array($keyword, $uniqueKeywords)) {
                            $uniqueKeywords[] = $keyword;
                        }
                        
                        break 2; // Переходим к следующей колонке
                    }
                }
            }
        }

        return [
            'total' => $totalMatches,
            'unique' => $uniqueKeywords,
            'keywords' => $matchedKeywords,
        ];
    }
    
    /**
     * Определяет, похожа ли строка на данные (а не на заголовки)
     * 
     * Признаки данных:
     * - Длинные конкретные описания (5+ слов)
     * - Содержит специфичные термины (демонтаж, монтаж с конкретными материалами)
     * - Содержит числа внутри текста ("до 5 см", "толщиной 10 мм")
     * 
     * Признаки заголовков:
     * - Короткие обобщающие термины (1-4 слова)
     * - Без конкретных деталей
     */
    private function isLikelyDataRow(array $rowValues): bool
    {
        $dataSignals = 0;
        $headerSignals = 0;
        
        foreach ($rowValues as $value) {
            $normalized = mb_strtolower(trim($value));
            $wordCount = count(explode(' ', $normalized));
            
            // Пропускаем пустые значения
            if (empty($normalized)) {
                continue;
            }
            
            // Признак данных: длинный текст (5+ слов)
            if ($wordCount >= 5) {
                $dataSignals++;
            }
            
            // Признак заголовка: короткий текст (1-3 слова)
            if ($wordCount >= 1 && $wordCount <= 3) {
                $headerSignals++;
            }
            
            // Признак данных: числа внутри текста ("до 5 см", "толщиной 100 мм")
            if (preg_match('/\d+\s*(см|мм|м|кг|т|шт)/u', $normalized)) {
                $dataSignals += 2; // Сильный сигнал
            }
            
            // Признак данных: конкретные глаголы действия
            $actionVerbs = ['демонтаж', 'монтаж', 'устройство', 'укладка', 'установка', 'разборка', 'снятие'];
            foreach ($actionVerbs as $verb) {
                if (str_contains($normalized, $verb) && $wordCount > 3) {
                    $dataSignals++; // Глагол + длинное описание = данные
                    break;
                }
            }
            
            // Признак заголовка: точное совпадение с общими терминами
            $headerTerms = ['наименование работ', 'единица измерения', 'количество', 'цена', 'стоимость', 'обоснование', 'ед.изм', 'кол-во'];
            foreach ($headerTerms as $term) {
                if ($normalized === $term || str_contains($normalized, $term)) {
                    $headerSignals += 2; // Сильный сигнал заголовка
                    break;
                }
            }
        }
        
        // Если больше признаков данных, чем заголовков - это строка с данными
        return $dataSignals > $headerSignals;
    }
}

