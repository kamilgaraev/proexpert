<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

class StyleExtractor
{
    public function extractStyles(string $filePath, int $startRow = 1, int $limit = 100): array
    {
        $filter = new class($startRow, $limit) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            private int $startRow;
            private int $endRow;

            public function __construct($startRow, $limit) {
                $this->startRow = $startRow;
                $this->endRow   = $startRow + $limit;
            }

            public function readCell(string $column, int $row, string $worksheetName = ''): bool {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        };

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadFilter($filter);
        $reader->setReadDataOnly(false);

        $spreadsheet = $reader->load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();

        $styles = [];
        foreach ($sheet->getRowIterator($startRow, $startRow + $limit) as $row) {
            $rowIndex      = $row->getRowIndex();
            $cellIterator  = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $colIndex = $cell->getColumn();
                $style    = $sheet->getStyle($colIndex . $rowIndex);
                $font     = $style->getFont();

                $styles[$rowIndex][$colIndex] = [
                    'is_bold'    => $font->getBold(),
                    'is_italic'  => $font->getItalic(),
                    'color'      => $font->getColor()->getARGB(),
                    'background' => $style->getFill()->getStartColor()->getARGB(),
                    'size'       => $font->getSize(),
                    'indent'     => $style->getAlignment()->getIndent(),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $styles;
    }

    public function summarizeRowStyles(array $rawStyles): array
    {
        $summary = [];

        $sizes = array_filter(array_map(
            fn($cells) => array_sum(array_column($cells, 'size')) / max(count($cells), 1),
            $rawStyles
        ));
        $baseSize = !empty($sizes) ? (array_sum($sizes) / count($sizes)) : 11.0;

        foreach ($rawStyles as $rowIndex => $cells) {
            if (empty($cells)) {
                continue;
            }

            $boldCount       = 0;
            $hasBackground   = false;
            $totalIndent     = 0;
            $totalSize       = 0.0;
            $count           = 0;

            foreach ($cells as $cell) {
                if ($cell['is_bold']) {
                    $boldCount++;
                }

                $bg = $cell['background'] ?? 'FF000000';
                if (!in_array($bg, ['FF000000', 'FFFFFFFF', '00000000', ''], true)) {
                    $hasBackground = true;
                }

                $totalIndent += (int)($cell['indent'] ?? 0);
                $totalSize   += (float)($cell['size'] ?? $baseSize);
                $count++;
            }

            $avgSize        = $count > 0 ? $totalSize / $count : $baseSize;
            $isBoldDominant = $count > 0 && ($boldCount / $count) >= 0.5;
            $avgIndent      = $count > 0 ? (int)round($totalIndent / $count) : 0;
            $sizeLevel      = (int)round(max(0, ($avgSize - $baseSize) / 2));

            $summary[$rowIndex] = [
                'is_bold_dominant' => $isBoldDominant,
                'has_background'   => $hasBackground,
                'indent_level'     => $avgIndent,
                'size_level'       => $sizeLevel,
            ];
        }

        return $summary;
    }
}
