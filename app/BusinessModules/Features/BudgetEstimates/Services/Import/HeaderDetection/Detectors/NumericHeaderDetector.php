<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\AbstractHeaderDetector;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NumericHeaderDetector extends AbstractHeaderDetector
{
    public function __construct()
    {
        $this->weight = 0.8; // Меньший вес, т.к. номера - вспомогательная информация
    }

    public function getName(): string
    {
        return 'numeric_header';
    }

    public function detectCandidates(Worksheet $sheet): array
    {
        $candidates = [];
        $maxRow = min($sheet->getHighestRow(), $this->maxRowsToScan);

        for ($row = 1; $row <= $maxRow; $row++) {
            $rowValues = $this->getRowValues($sheet, $row);

            if (empty($rowValues)) {
                continue;
            }

            $numericInfo = $this->analyzeNumericSequence($rowValues);

            // Если это последовательность номеров колонок
            if ($numericInfo['is_sequence'] && $numericInfo['count'] >= 5) {
                // Проверяем строку выше - там должны быть реальные заголовки
                $prevRow = $this->getRowValues($sheet, max($row - 1, 1));
                
                $candidates[] = [
                    'row' => $row - 1, // Используем строку ВЫШЕ номеров
                    'detector' => $this->getName(),
                    'numeric_row' => $row,
                    'is_numeric_sequence' => true,
                    'sequence_length' => $numericInfo['count'],
                    'has_header_above' => !empty($prevRow),
                    'filled_columns' => count($prevRow),
                    'raw_values' => $prevRow,
                ];
            }
        }

        return $candidates;
    }

    public function scoreCandidate(array $candidate, array $context = []): float
    {
        $score = 0.0;

        // 1. Если есть заголовок над номерами (0-0.4)
        if ($candidate['has_header_above'] ?? false) {
            $filledColumns = $candidate['filled_columns'] ?? 0;
            $score += min($filledColumns / 20, 0.4);
        }

        // 2. Бонус за длину последовательности (0-0.3)
        $sequenceLength = $candidate['sequence_length'] ?? 0;
        $score += min($sequenceLength / 20, 0.3);

        // 3. Бонус за позицию (0-0.2)
        $row = $candidate['row'] ?? 0;
        if ($row >= 20 && $row <= 40) {
            $score += 0.2;
        } elseif ($row >= 10 && $row < 20) {
            $score += 0.1;
        }

        // 4. Небольшой бонус за последовательность номеров (0-0.1)
        if ($candidate['is_numeric_sequence'] ?? false) {
            $score += 0.1;
        }

        return min($score, 1.0);
    }

    /**
     * Анализирует является ли строка последовательностью номеров
     *
     * @param array $rowValues
     * @return array
     */
    private function analyzeNumericSequence(array $rowValues): array
    {
        $numbers = [];
        
        foreach ($rowValues as $value) {
            if (is_numeric($value)) {
                $numbers[] = (int)$value;
            }
        }

        if (count($numbers) < 3) {
            return ['is_sequence' => false, 'count' => 0];
        }

        // Проверяем является ли это последовательностью 1, 2, 3...
        $isSequence = true;
        $expectedValue = min($numbers);

        foreach ($numbers as $num) {
            if ($num !== $expectedValue) {
                // Допускаем небольшие пропуски
                if ($num > $expectedValue + 2) {
                    $isSequence = false;
                    break;
                }
            }
            $expectedValue++;
        }

        return [
            'is_sequence' => $isSequence,
            'count' => count($numbers),
            'min' => min($numbers),
            'max' => max($numbers),
        ];
    }
}

