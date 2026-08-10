<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final readonly class BoundedSpreadsheetReadFilter implements IReadFilter
{
    public function __construct(
        private int $maxRows,
        private int $maxColumns,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= 1
            && $row <= $this->maxRows
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }
}
