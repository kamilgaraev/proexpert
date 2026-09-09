<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class SpreadsheetSampleLoader
{
    public function load(string $filePath, int $maxRows): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadFilter(new class($maxRows) implements IReadFilter
        {
            public function __construct(private readonly int $maxRows) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRows;
            }
        });

        return $reader->load($filePath);
    }
}
