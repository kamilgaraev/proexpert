<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\AbstractHeaderDetector;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MultilineHeaderDetector extends AbstractHeaderDetector
{
    public function __construct()
    {
        $this->weight = 1.2;
    }

    public function getName(): string
    {
        return 'multiline_header';
    }

    public function detectCandidates(Worksheet $sheet): array
    {
        $candidates = [];
        $maxRow = min($sheet->getHighestRow(), $this->maxRowsToScan);

        for ($row = 1; $row < $maxRow; $row++) {
            $currentRow = $this->getRowValues($sheet, $row);
            $nextRow = $this->getRowValues($sheet, $row + 1);

            if (empty($currentRow) || $this->isServiceInfo($currentRow)) {
                continue;
            }

            // Проверяем является ли это многострочным заголовком
            $multilineInfo = $this->analyzeMultilineHeader($sheet, $row, $currentRow, $nextRow);

            if ($multilineInfo['is_multiline']) {
                $candidates[] = [
                    'row' => $multilineInfo['best_row'], // Строка с наибольшим количеством колонок
                    'detector' => $this->getName(),
                    'is_multiline' => true,
                    'start_row' => $row,
                    'end_row' => $row + 1,
                    'filled_columns_current' => count($currentRow),
                    'filled_columns_next' => count($nextRow),
                    'best_filled' => $multilineInfo['best_filled'],
                    'raw_values' => $multilineInfo['best_values'],
                ];
            }
        }

        return $candidates;
    }

    public function scoreCandidate(array $candidate, array $context = []): float
    {
        $score = 0.0;

        // 1. Бонус за количество колонок в лучшей строке (0-0.5)
        $bestFilled = $candidate['best_filled'] ?? 0;
        $score += min($bestFilled / 20, 0.5);

        // 2. Бонус за многострочность (0-0.2)
        if ($candidate['is_multiline'] ?? false) {
            $score += 0.2;
        }

        // 3. Бонус за позицию (0-0.15)
        $row = $candidate['row'] ?? 0;
        if ($row >= 20 && $row <= 40) {
            $score += 0.15;
        } elseif ($row >= 10 && $row < 20) {
            $score += 0.1;
        }

        // 4. Бонус за баланс между строками (0-0.15)
        $currentFilled = $candidate['filled_columns_current'] ?? 0;
        $nextFilled = $candidate['filled_columns_next'] ?? 0;
        
        // Если обе строки хорошо заполнены - это хороший признак
        if ($currentFilled >= 3 && $nextFilled >= 5) {
            $score += 0.15;
        }

        return min($score, 1.0);
    }

    /**
     * Анализирует являются ли две строки многострочным заголовком
     *
     * @param Worksheet $sheet
     * @param int $row
     * @param array $currentRow
     * @param array $nextRow
     * @return array
     */
    private function analyzeMultilineHeader(Worksheet $sheet, int $row, array $currentRow, array $nextRow): array
    {
        // Проверяем что следующая строка не числовая (не данные)
        $nextHasNumbers = $this->hasNumericData($nextRow);
        
        if ($nextHasNumbers) {
            return ['is_multiline' => false];
        }

        $currentFilled = count($currentRow);
        $nextFilled = count($nextRow);

        // Если следующая строка имеет значительно больше колонок - это подзаголовки
        $isMultiline = false;
        $bestRow = $row;
        $bestFilled = $currentFilled;
        $bestValues = $currentRow;

        if ($nextFilled > $currentFilled * 1.5) {
            // Следующая строка лучше
            $isMultiline = true;
            $bestRow = $row + 1;
            $bestFilled = $nextFilled;
            $bestValues = $nextRow;
        } elseif ($nextFilled >= 5 && $currentFilled >= 3) {
            // Обе строки хорошо заполнены - многострочный заголовок
            $isMultiline = true;
            // Выбираем строку с большим количеством колонок
            if ($nextFilled > $currentFilled) {
                $bestRow = $row + 1;
                $bestFilled = $nextFilled;
                $bestValues = $nextRow;
            }
        }

        return [
            'is_multiline' => $isMultiline,
            'best_row' => $bestRow,
            'best_filled' => $bestFilled,
            'best_values' => $bestValues,
        ];
    }

    /**
     * Проверяет содержит ли строка числовые данные
     *
     * @param array $rowValues
     * @return bool
     */
    private function hasNumericData(array $rowValues): bool
    {
        $numericCount = 0;
        
        foreach ($rowValues as $value) {
            if (is_numeric($value)) {
                $numericCount++;
            }
        }

        // Если больше 30% значений - числа, это скорее всего данные
        return $numericCount > 0 && $numericCount / max(count($rowValues), 1) > 0.3;
    }
}

