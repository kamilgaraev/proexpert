<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SpreadsheetTableReader
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public function readRows(string $filePath, ?int $maxRows = null, ?int $sheetIndex = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        try {
            if ($sheetIndex !== null && ($sheetIndex < 0 || $sheetIndex >= $spreadsheet->getSheetCount())) {
                return [];
            }

            $sheet = $sheetIndex !== null
                ? $spreadsheet->getSheet($sheetIndex)
                : $spreadsheet->getActiveSheet();
            $rows = $this->rowsFromWorksheet($sheet, $maxRows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $rows;
    }

    /**
     * @return array<int, array{index: int, name: string, rows: array<int, array<int, mixed>>}>
     */
    public function readWorksheets(string $filePath, ?int $maxRows = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        try {
            $worksheets = [];
            $index = 0;

            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $worksheets[] = [
                    'index' => $index,
                    'name' => $sheet->getTitle(),
                    'rows' => $this->rowsFromWorksheet($sheet, $maxRows),
                ];
                $index++;
            }

            return $worksheets;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rowsFromWorksheet(Worksheet $sheet, ?int $maxRows): array
    {
        $highestRow = $sheet->getHighestDataRow();
        if ($maxRows !== null) {
            $highestRow = min($highestRow, $maxRows);
        }

        $highestColumn = $sheet->getHighestDataColumn();
        $rows = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $rows[$row] = array_map(
                static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
                $values
            );
        }

        return $rows;
    }
}
