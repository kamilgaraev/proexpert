<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final readonly class BoundedSpreadsheetReadFilter implements IReadFilter
{
    /** @param array<string, array{rows: int, columns: int, cells: int}> $sheetBounds */
    public function __construct(
        private array $sheetBounds,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        $bounds = $this->sheetBounds[$worksheetName] ?? null;
        if ($bounds === null) {
            return false;
        }

        return $row >= 1
            && $row <= $bounds['rows']
            && self::columnIndex($columnAddress) <= $bounds['columns']
            && (($row - 1) * $bounds['columns']) + self::columnIndex($columnAddress) <= $bounds['cells'];
    }

    private static function columnIndex(string $columnAddress): int
    {
        $index = 0;
        foreach (str_split(strtoupper($columnAddress)) as $character) {
            $index = ($index * 26) + (ord($character) - 64);
        }

        return $index;
    }
}
