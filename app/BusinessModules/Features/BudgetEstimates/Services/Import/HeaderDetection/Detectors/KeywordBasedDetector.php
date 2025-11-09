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
        $rawValues = $candidate['raw_values'] ?? [];
        
        // ============================================
        // ЭТАП 0: СУПЕР-БЫСТРАЯ ПРОВЕРКА НА ЯВНЫЕ ЗАГОЛОВКИ
        // ============================================
        $superHeaderCount = $this->countSuperHeaderTerms($rawValues);
        if ($superHeaderCount >= 2) {
            // Если 2+ СУПЕР-явных терминов - это 100% заголовки
            return 0.99;
        }
        
        // ============================================
        // ЭТАП 1: ЖЕСТКАЯ ФИЛЬТРАЦИЯ СТРОК ДАННЫХ
        // ============================================
        $isDefinitelyData = $this->isDefinitelyDataRow($rawValues);
        if ($isDefinitelyData) {
            // Это точно НЕ заголовок - возвращаем минимальный score
            return 0.01;
        }
        
        // ============================================
        // ЭТАП 2: ПОИСК ЯВНЫХ ЗАГОЛОВОЧНЫХ ТЕРМИНОВ
        // ============================================
        $headerScore = $this->calculateHeaderScore($rawValues);
        if ($headerScore >= 0.85) {
            // Явные заголовки - сразу возвращаем высокий score
            return $headerScore;
        }
        
        // ============================================
        // ЭТАП 3: ОБЩИЙ РАСЧЕТ SCORE
        // ============================================
        $score = $headerScore; // Начинаем с header score (0-0.85)

        // Бонус за keyword matches
        $keywordMatches = $candidate['keyword_matches'] ?? 0;
        $score += min($keywordMatches / 40, 0.1);

        // Бонус за количество заполненных колонок
        $filledColumns = $candidate['filled_columns'] ?? 0;
        $score += min($filledColumns / 30, 0.05);

        return min($score, 1.0);
    }

    /**
     * СУПЕР-БЫСТРАЯ проверка на явные заголовочные термины
     * 
     * Ищет ТОЛЬКО самые очевидные заголовочные термины
     * с агрессивной нормализацией (убирает пробелы, точки, регистр)
     */
    private function countSuperHeaderTerms(array $rowValues): int
    {
        $superTerms = [
            'наименованиеработ',
            'наименование',
            'едизм',
            'единицаизмерения',
            'количество',
            'колво',
            'цена',
            'ценазаед',
            'стоимость',
            'обоснование',
            'шифр',
        ];
        
        $matchedCount = 0;
        
        foreach ($rowValues as $value) {
            // АГРЕССИВНАЯ нормализация: убираем ВСЕ пробелы, точки, тире, запятые
            $normalized = mb_strtolower(trim($value));
            $normalized = str_replace([' ', '.', '-', '_', ',', ':', ';'], '', $normalized);
            
            // Проверяем точное совпадение ИЛИ вхождение
            foreach ($superTerms as $term) {
                if ($normalized === $term || str_contains($normalized, $term)) {
                    $matchedCount++;
                    break; // Один термин на колонку
                }
            }
        }
        
        return $matchedCount;
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
     * ЖЕСТКАЯ проверка: это точно строка ДАННЫХ (а не заголовков)
     * 
     * Признаки строки данных:
     * 1. Начинается с номера (1, 2, 3...) в первой колонке
     * 2. Содержит короткие коды во второй/третьей колонке (В, Р, О, М, -)
     * 3. Содержит длинное описание работы (5+ слов с глаголами действия)
     * 4. Содержит конкретные числа с единицами измерения внутри текста
     */
    private function isDefinitelyDataRow(array $rowValues): bool
    {
        if (count($rowValues) < 2) {
            return false; // Слишком мало колонок
        }
        
        $signals = 0;
        
        // Преобразуем ассоциативный массив ['A' => val, 'B' => val] в индексированный [0 => val, 1 => val]
        $values = array_values($rowValues);
        
        // СИГНАЛ 1: Первая колонка - это номер строки (1, 2, 3, 10, 11 и т.д.)
        $firstCol = trim($values[0] ?? '');
        if (preg_match('/^\d+(\.\d+)?$/', $firstCol)) {
            $signals += 4; // СУПЕР-сильный сигнал
        }
        
        // СИГНАЛ 2: Вторая или третья колонка - короткий код (В, Р, О, М, -, Б и т.д.)
        $secondCol = mb_strtolower(trim($values[1] ?? ''));
        $thirdCol = mb_strtolower(trim($values[2] ?? ''));
        
        if (in_array($secondCol, ['в', 'р', 'о', 'м', '-', 'б', 'вр', 'мр']) || 
            (mb_strlen($secondCol) <= 2 && !empty($secondCol) && !$this->isHeaderTerm($secondCol))) {
            $signals += 3; // Сильный сигнал
        }
        
        if (in_array($thirdCol, ['в', 'р', 'о', 'м', '-', 'б', 'вр', 'мр']) || 
            (mb_strlen($thirdCol) <= 2 && !empty($thirdCol) && !$this->isHeaderTerm($thirdCol))) {
            $signals += 2;
        }
        
        // СИГНАЛ 3: Есть колонка с длинным описанием работы (5+ слов с глаголами действия)
        $actionVerbs = ['демонтаж', 'монтаж', 'устройство', 'укладка', 'установка', 
                        'разборка', 'снятие', 'окраска', 'штукатурка', 'облицовка',
                        'изоляция', 'прокладка', 'сверление', 'резка', 'крепление'];
        
        foreach ($values as $value) {
            $normalized = mb_strtolower(trim($value));
            $wordCount = count(array_filter(explode(' ', $normalized), fn($w) => mb_strlen($w) > 2));
            
            if ($wordCount >= 5) {
                foreach ($actionVerbs as $verb) {
                    if (str_contains($normalized, $verb)) {
                        $signals += 3; // Очень сильный сигнал
                        break 2; // Выходим из обоих циклов
                    }
                }
            }
        }
        
        // СИГНАЛ 4: Содержит числа с единицами измерения в тексте
        foreach ($values as $value) {
            $normalized = mb_strtolower(trim($value));
            if (preg_match('/\d+\s*(до|от)?\s*\d*\s*(см|мм|м|кг|т|л)/u', $normalized)) {
                $signals += 2;
                break;
            }
        }
        
        // СИГНАЛ 5: Последняя колонка - короткая единица измерения (м², шт, м3)
        $lastCol = mb_strtolower(trim($values[count($values) - 1] ?? ''));
        $units = ['м²', 'м2', 'м³', 'м3', 'шт', 'кг', 'т', 'л', 'м', 'п.м', 'кв.м', 'куб.м', 'мп', 'м.п'];
        foreach ($units as $unit) {
            if ($lastCol === $unit || str_contains($lastCol, $unit)) {
                $signals += 1;
                break;
            }
        }
        
        // Если набрано 5+ сигналов - это точно данные
        return $signals >= 5;
    }
    
    /**
     * Рассчитать score заголовочности строки
     * 
     * Возвращает значение от 0 до 1, где:
     * - 0.95+ = явные заголовки (4+ точных термина)
     * - 0.85+ = вероятные заголовки (3 термина)
     * - 0.70+ = возможные заголовки (2 термина)
     * - < 0.70 = слабые совпадения
     */
    private function calculateHeaderScore(array $rowValues): float
    {
        // Явные заголовочные термины с весами
        $headerTerms = [
            // Суперсильные термины (вес 1.0)
            'наименование работ' => 1.0,
            'наименование работ и затрат' => 1.0,
            'наименование' => 0.9,
            
            // Сильные термины (вес 0.8-0.9)
            'единица измерения' => 0.9,
            'ед.изм' => 0.9,
            'ед. изм' => 0.9,
            'ед.изм.' => 0.9,
            'ед изм' => 0.9,
            
            'количество' => 0.9,
            'кол-во' => 0.9,
            'кол.во' => 0.9,
            'кол.' => 0.8,
            'кол-во' => 0.9,
            
            'цена за ед' => 0.9,
            'цена за единицу' => 0.9,
            'цена' => 0.8,
            
            'стоимость единицы' => 0.9,
            'стоимость' => 0.8,
            'сумма' => 0.8,
            
            // Средние термины (вес 0.6-0.7)
            'обоснование' => 0.7,
            'шифр' => 0.7,
            'код' => 0.6,
            'номер' => 0.6,
            '№' => 0.6,
        ];
        
        $totalScore = 0.0;
        $matchedCount = 0;
        
        foreach ($rowValues as $value) {
            $normalized = mb_strtolower(trim($value));
            
            // Пропускаем пустые
            if (empty($normalized)) {
                continue;
            }
            
            // Нормализуем пробелы и точки
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = str_replace(['..', '. .'], '.', $normalized);
            
            // Проверяем каждый термин
            foreach ($headerTerms as $term => $weight) {
                // Точное совпадение или вхождение
                if ($normalized === $term || str_contains($normalized, $term)) {
                    $totalScore += $weight;
                    $matchedCount++;
                    break; // Один термин на колонку
                }
            }
        }
        
        // Нормализуем score
        if ($matchedCount >= 4) {
            return min(0.95 + ($matchedCount - 4) * 0.01, 0.99);
        } elseif ($matchedCount >= 3) {
            return 0.85 + $totalScore * 0.05;
        } elseif ($matchedCount >= 2) {
            return 0.70 + $totalScore * 0.08;
        } else {
            return $totalScore * 0.5; // Слабое совпадение
        }
    }
    
    /**
     * Проверяет, является ли значение заголовочным термином
     * (чтобы не считать короткие заголовки как коды)
     */
    private function isHeaderTerm(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        
        $headerTerms = [
            '№', 'no', '#',
            'ед', 'кол', 'qty',
        ];
        
        return in_array($normalized, $headerTerms);
    }
}

