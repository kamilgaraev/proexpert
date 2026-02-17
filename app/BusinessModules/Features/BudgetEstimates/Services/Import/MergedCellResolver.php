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
        
        // Проверяем следующие 2 строки для подзаголовков (многоуровневые заголовки)
        $nextRow1 = $headerRow + 1;
        $nextRow2 = $headerRow + 2;
        $hasSubheaders1 = $this->hasSubheaders($sheet, $nextRow1);
        $hasSubheaders2 = $this->hasSubheaders($sheet, $nextRow2);
        
        Log::info('[MergedCellResolver] Resolving headers', [
            'header_row' => $headerRow,
            'highest_column' => $highestColumn,
            'merge_ranges_count' => count($mergeRanges),
            'has_subheaders_row1' => $hasSubheaders1,
            'has_subheaders_row2' => $hasSubheaders2,
        ]);

        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $currentValue = $sheet->getCell($col . $headerRow)->getValue();
            $headerText = '';

            // Основной заголовок
            if ($currentValue !== null && trim((string)$currentValue) !== '') {
                $headerText = trim((string)$currentValue);
            } else {
                // Если в основном заголовке пусто - ищем значение в объединенной ячейке слева
                $headerText = $this->findMergedHeaderValue($sheet, $headerRow, $col, $mergeRanges);
            }

            // Комбинируем с подзаголовками (строка +1)
            if ($hasSubheaders1) {
                $subheaderValue1 = $sheet->getCell($col . $nextRow1)->getValue();
                
                if ($subheaderValue1 !== null && trim((string)$subheaderValue1) !== '' && !is_numeric($subheaderValue1)) {
                    $subheaderText1 = trim((string)$subheaderValue1);
                    
                    if ($headerText !== '') {
                        $headerText = $headerText . ' ' . $subheaderText1;
                    } else {
                        $headerText = $subheaderText1;
                    }
                }
            }

            // Комбинируем с подзаголовками (строка +2)
            if ($hasSubheaders2) {
                $subheaderValue2 = $sheet->getCell($col . $nextRow2)->getValue();
                
                if ($subheaderValue2 !== null && trim((string)$subheaderValue2) !== '' && !is_numeric($subheaderValue2)) {
                    $subheaderText2 = trim((string)$subheaderValue2);
                    
                    // Только добавляем если это не повтор
                    if (!str_contains($headerText, $subheaderText2)) {
                        if ($headerText !== '') {
                            $headerText = $headerText . ' ' . $subheaderText2;
                        } else {
                            $headerText = $subheaderText2;
                        }
                    }
                }
            }

            $headers[$col] = $headerText;
        }

        Log::debug('[MergedCellResolver] Resolved headers', [
            'headers_count' => count(array_filter($headers)),
            'sample' => array_slice($headers, 0, 15),
        ]);

        return $headers;
    }

    /**
     * Ищет значение заголовка в объединенной ячейке слева
     */
    private function findMergedHeaderValue(Worksheet $sheet, int $row, string $col, array $mergeRanges): string
    {
        foreach ($mergeRanges as $range) {
            // Проверяем попадает ли текущая колонка в диапазон объединения
            if ($col >= $range['start_col'] && $col <= $range['end_col']) {
                // Берем значение из начала объединенной ячейки
                $value = $sheet->getCell($range['start_col'] . $row)->getValue();
                if ($value !== null && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        
        return '';
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
        $sheetHighestIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheetHighest);
        
        Log::debug('[MergedCellResolver] Starting column detection', [
            'sheet_highest' => $sheetHighest,
            'start_row' => $startRow,
        ]);
        
        // КРИТИЧНО: PhpSpreadsheet->getHighestColumn() может ошибаться из-за merged cells
        // Нужно сканировать ВСЕ строки файла для поиска максимальной колонки
        
        $maxFilledColumnIndex = 1;
        $highestRow = $sheet->getHighestRow();
        
        // Сканируем ВСЕ строки (или максимум 500 для производительности)
        $rowsToCheck = min(500, $highestRow);
        
        // ВАЖНО: Сканируем до колонки Z (26) или sheetHighest, что больше
        // Но не перебарщиваем, скажем до 100 колонок (CV) если sheetHighest маленький
        $maxColumnIndexToCheck = max(26, $sheetHighestIndex, 50); // Scan at least 50 columns
        
        for ($row = 1; $row <= $rowsToCheck; $row++) {
            for ($colIdx = 1; $colIdx <= $maxColumnIndexToCheck; $colIdx++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                try {
                    $cell = $sheet->getCell($col . $row);
                    $value = $cell->getValue();
                    
                    if ($value !== null && trim((string)$value) !== '') {
                        if ($colIdx > $maxFilledColumnIndex) {
                            $maxFilledColumnIndex = $colIdx;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        $finalHighestIndex = max($sheetHighestIndex, $maxFilledColumnIndex);
        $finalHighest = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($finalHighestIndex);

        Log::info('[MergedCellResolver] Actual highest column detected', [
            'sheet_highest' => $sheetHighest,
            'actual_highest' => $finalHighest,
            'rows_scanned' => $rowsToCheck,
        ]);

        return $finalHighest;
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

        $actualHighestIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($actualHighest);

        for ($colIdx = 1; $colIdx <= $actualHighestIndex; $colIdx++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
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
        
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
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

