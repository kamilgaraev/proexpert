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
    public function readRows(string $filePath, ?int $maxRows = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $this->rowsFromWorksheet($sheet, $maxRows);
        $spreadsheet->disconnectWorksheets();

        return $rows;
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
