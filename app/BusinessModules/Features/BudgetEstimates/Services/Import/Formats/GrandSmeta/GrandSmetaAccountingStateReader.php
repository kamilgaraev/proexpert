<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class GrandSmetaAccountingStateReader
{
    public function detect(string $filePath, Worksheet $sheet, array $mapping, int $headerRow): array
    {
        if (!isset($mapping['position_number'], $mapping['code'], $mapping['name'])) {
            return [];
        }
        $producerConfirmed = false;
        foreach ($sheet->getRowIterator(1, max(1, $headerRow)) as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(true);
            foreach ($cells as $cell) {
                if (mb_stripos($this->text($cell->getCalculatedValue()), 'ГРАНД-Смета') !== false) {
                    $producerConfirmed = true;
                    break 2;
                }
            }
        }
        if (!$producerConfirmed) {
            return [];
        }
        $lastDataColumn = max(array_map(static fn (string $column): int => Coordinate::columnIndexFromString($column), array_values($mapping)));
        $mirrors = [];
        foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
            $rowNumber = $row->getRowIndex();
            $number = preg_split('/\s+/u', $this->text($sheet->getCell($mapping['position_number'].$rowNumber)->getCalculatedValue()))[0] ?? '';
            $name = $this->text($sheet->getCell($mapping['name'].$rowNumber)->getCalculatedValue());
            if (preg_match('/^\d+[а-яa-z-]*$/iu', $number) !== 1 || $name === ''
                || $this->text($sheet->getCell($mapping['code'].$rowNumber)->getCalculatedValue()) === '') {
                continue;
            }
            $mirrors[$rowNumber] = [];
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(true);
            foreach ($cells as $cell) {
                if (Coordinate::columnIndexFromString($cell->getColumn()) > $lastDataColumn && $this->text($cell->getCalculatedValue()) === $name) {
                    $mirrors[$rowNumber][] = $cell->getColumn();
                }
            }
        }
        if (count($mirrors) < 2 || in_array([], $mirrors, true)) {
            return [];
        }
        $columns = array_values(array_unique([$mapping['position_number'], $mapping['code'], $mapping['name']]));
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly($sheet->getTitle());
        $reader->setReadFilter(new class($columns, $mirrors, $sheet->getTitle()) implements IReadFilter
        {
            public function __construct(private array $columns, private array $rows, private string $sheetName)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $worksheetName === $this->sheetName && isset($this->rows[$row]) && in_array($columnAddress, $this->columns, true);
            }
        });
        $styles = $reader->load($filePath);
        try {
            $styledSheet = $styles->getSheetByName($sheet->getTitle());
            if ($styledSheet === null) {
                return [];
            }
            $inactive = [];
            $counts = ['active' => [], 'inactive' => []];
            foreach ($mirrors as $rowNumber => $rowMirrors) {
                $gray = true;
                foreach ($columns as $column) {
                    $color = $styledSheet->getStyle($column.$rowNumber)->getFont()->getColor();
                    $gray = $gray && strtoupper($color->getRGB()) === '7F7F7F';
                }
                $inactive[$rowNumber] = $gray;
                foreach ($rowMirrors as $column) {
                    $group = $gray ? 'inactive' : 'active';
                    $counts[$group][$column] = ($counts[$group][$column] ?? 0) + 1;
                }
            }
            $inactiveCount = count(array_filter($inactive));
            $activeCount = count($inactive) - $inactiveCount;
            if ($inactiveCount === 0 || $activeCount === 0) {
                return [];
            }
            $activeColumns = array_keys(array_filter($counts['active'], static fn (int $count, string $column): bool => $count === $activeCount && !isset($counts['inactive'][$column]), ARRAY_FILTER_USE_BOTH));
            $inactiveColumns = array_keys(array_filter($counts['inactive'], static fn (int $count, string $column): bool => $count === $inactiveCount && !isset($counts['active'][$column]), ARRAY_FILTER_USE_BOTH));
            if (count($activeColumns) !== 1 || count($inactiveColumns) !== 1) {
                return [];
            }
            $result = [];
            foreach ($inactive as $rowNumber => $isInactive) {
                if ($isInactive) {
                    $result[$rowNumber] = [
                        'marker' => 'grand_export_inactive',
                        'font_color' => '7F7F7F',
                        'active_mirror_column' => $activeColumns[0],
                        'inactive_mirror_column' => $inactiveColumns[0],
                        'source_cell' => $inactiveColumns[0].$rowNumber,
                    ];
                }
            }

            return $result;
        } finally {
            $styles->disconnectWorksheets();
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';
    }
}
