<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\AbstractHeaderDetector;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class MergedCellsAwareDetector extends AbstractHeaderDetector
{
    public function __construct()
    {
        $this->weight = 1.5; // Увеличенный вес, т.к. это критично
    }

    public function getName(): string
    {
        return 'merged_cells_aware';
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

            $realFilledCount = $this->getRealFilledCount($sheet, $row);
            $mergedCellsInfo = $this->analyzeMergedCells($sheet, $row);

            // Принимаем строки с минимум 5 реальными значениями
            if ($realFilledCount >= 5) {
                $candidates[] = [
                    'row' => $row,
                    'detector' => $this->getName(),
                    'real_filled_count' => $realFilledCount,
                    'merged_cells' => $mergedCellsInfo['count'],
                    'has_merged_cells' => $mergedCellsInfo['has_merged'],
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

        // 1. Количество реальных колонок (0-0.5) - главный критерий
        $realFilled = $candidate['real_filled_count'] ?? 0;
        $score += min($realFilled / 20, 0.5);

        // 2. Штраф за объединенные ячейки (0-0.2)
        if (!($candidate['has_merged_cells'] ?? false)) {
            $score += 0.2; // Бонус за отсутствие merged cells
        } else {
            $mergedCount = $candidate['merged_cells'] ?? 0;
            $penalty = min($mergedCount / 10, 0.15);
            $score -= $penalty;
        }

        // 3. Небольшой бонус за разумную позицию (0-0.1)
        $row = $candidate['row'] ?? 0;
        if ($row >= 5 && $row <= 50) {
            $score += 0.1; // Заголовки обычно в пределах первых 50 строк
        }

        // 4. Бонус за большое количество колонок (0-0.2)
        $filledColumns = $candidate['filled_columns'] ?? 0;
        if ($filledColumns >= 10) {
            $score += 0.2;
        } elseif ($filledColumns >= 7) {
            $score += 0.1;
        }

        return max(min($score, 1.0), 0.0);
    }

    /**
     * Получает реальное количество заполненных ячеек (без учета merged)
     *
     * @param Worksheet $sheet
     * @param int $row
     * @return int
     */
    private function getRealFilledCount(Worksheet $sheet, int $row): int
    {
        $count = 0;
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $cell = $sheet->getCell($col . $row);
            $value = $cell->getValue();

            if ($value !== null && trim((string)$value) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Анализирует объединенные ячейки в строке
     *
     * @param Worksheet $sheet
     * @param int $row
     * @return array
     */
    private function analyzeMergedCells(Worksheet $sheet, int $row): array
    {
        $mergedCount = 0;
        $hasMerged = false;
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $cell = $sheet->getCell($col . $row);
            
            if ($cell->isInMergeRange()) {
                $mergedCount++;
                $hasMerged = true;
            }
        }

        return [
            'count' => $mergedCount,
            'has_merged' => $hasMerged,
        ];
    }
}

