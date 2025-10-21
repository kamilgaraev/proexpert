<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class MergedCellResolver
{
    /**
     * Разрешает объединенные заголовки, анализируя несколько строк
     *
     * @param Worksheet $sheet
     * @param int $headerRow
     * @return array Массив заголовков с ключами - буквами колонок
     */
    public function resolveHeaders(Worksheet $sheet, int $headerRow): array
    {
        $headers = [];
        $highestColumn = $this->getActualHighestColumn($sheet, $headerRow);
        
        // Получаем информацию об объединениях
        $mergeRanges = $this->getMergeRanges($sheet, $headerRow);
        
        // Проверяем следующую строку для подзаголовков
        $nextRow = $headerRow + 1;
        $hasSubheaders = $this->hasSubheaders($sheet, $nextRow);
        
        Log::info('[MergedCellResolver] Resolving headers', [
            'header_row' => $headerRow,
            'highest_column' => $highestColumn,
            'merge_ranges_count' => count($mergeRanges),
            'has_subheaders' => $hasSubheaders,
        ]);

        foreach (range('A', $highestColumn) as $col) {
            $currentValue = $sheet->getCell($col . $headerRow)->getValue();
            $headerText = '';

            if ($currentValue !== null && trim((string)$currentValue) !== '') {
                $headerText = trim((string)$currentValue);
            }

            // Если есть подзаголовки, комбинируем значения
            if ($hasSubheaders) {
                $subheaderValue = $sheet->getCell($col . $nextRow)->getValue();
                
                if ($subheaderValue !== null && trim((string)$subheaderValue) !== '' && !is_numeric($subheaderValue)) {
                    $subheaderText = trim((string)$subheaderValue);
                    
                    // Комбинируем если текущий заголовок не пуст
                    if ($headerText !== '') {
                        $headerText = $headerText . ' ' . $subheaderText;
                    } else {
                        $headerText = $subheaderText;
                    }
                }
            }

            $headers[$col] = $headerText;
        }

        Log::debug('[MergedCellResolver] Resolved headers', [
            'headers_count' => count(array_filter($headers)),
            'sample' => array_slice($headers, 0, 10),
        ]);

        return $headers;
    }

    /**
     * Получает реальную последнюю колонку на основе анализа данных
     *
     * @param Worksheet $sheet
     * @param int $startRow
     * @return string
     */
    public function getActualHighestColumn(Worksheet $sheet, int $startRow): string
    {
        $sheetHighest = $sheet->getHighestColumn();
        
        // Анализируем строки с данными (после заголовков) чтобы определить реальное количество колонок
        $maxFilledColumn = 'A';
        $rowsToCheck = min(20, $sheet->getHighestRow() - $startRow);
        
        for ($i = 1; $i <= $rowsToCheck; $i++) {
            $row = $startRow + $i;
            
            foreach (range('A', $sheetHighest) as $col) {
                $value = $sheet->getCell($col . $row)->getValue();
                
                if ($value !== null && trim((string)$value) !== '') {
                    if ($col > $maxFilledColumn) {
                        $maxFilledColumn = $col;
                    }
                }
            }
        }

        Log::debug('[MergedCellResolver] Actual highest column', [
            'sheet_highest' => $sheetHighest,
            'actual_highest' => $maxFilledColumn,
        ]);

        return max($sheetHighest, $maxFilledColumn);
    }

    /**
     * Получает информацию об объединенных ячейках в строке
     *
     * @param Worksheet $sheet
     * @param int $row
     * @return array
     */
    public function getMergeRanges(Worksheet $sheet, int $row): array
    {
        $ranges = [];
        $mergedCells = $sheet->getMergeCells();

        foreach ($mergedCells as $range) {
            // Проверяем содержит ли диапазон нашу строку
            if (preg_match('/([A-Z]+)(\d+):([A-Z]+)(\d+)/', $range, $matches)) {
                $startRow = (int)$matches[2];
                $endRow = (int)$matches[4];
                
                if ($row >= $startRow && $row <= $endRow) {
                    $ranges[] = [
                        'range' => $range,
                        'start_col' => $matches[1],
                        'end_col' => $matches[3],
                        'start_row' => $startRow,
                        'end_row' => $endRow,
                    ];
                }
            }
        }

        return $ranges;
    }

    /**
     * Расширяет заголовки до реальных колонок
     *
     * @param array $headers
     * @param Worksheet $sheet
     * @return array
     */
    public function expandToRealColumns(array $headers, Worksheet $sheet): array
    {
        $expanded = [];
        $actualHighest = $this->getActualHighestColumn($sheet, 1);

        foreach (range('A', $actualHighest) as $col) {
            $expanded[$col] = $headers[$col] ?? '';
        }

        return $expanded;
    }

    /**
     * Проверяет есть ли подзаголовки в следующей строке
     *
     * @param Worksheet $sheet
     * @param int $row
     * @return bool
     */
    private function hasSubheaders(Worksheet $sheet, int $row): bool
    {
        $textCount = 0;
        $numberCount = 0;
        
        $highestColumn = $sheet->getHighestColumn();
        
        foreach (range('A', $highestColumn) as $col) {
            $value = $sheet->getCell($col . $row)->getValue();
            
            if ($value !== null && trim((string)$value) !== '') {
                if (is_numeric($value)) {
                    $numberCount++;
                } else {
                    $textCount++;
                }
            }
        }

        // Если в строке преимущественно текст (не числа), это подзаголовки
        return $textCount > 0 && $textCount > $numberCount;
    }
}

