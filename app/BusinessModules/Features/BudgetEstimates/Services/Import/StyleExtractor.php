<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StyleExtractor
{
    /**
     * Reads the file and extracts style information for a specific row range.
     * This uses PhpSpreadsheet but should be used sparingly (e.g. only for headers).
     *
     * @param string $filePath
     * @param int $startRow
     * @param int $limit
     * @return array Array of style data indexed by row number and column letter
     */
    public function extractStyles(string $filePath, int $startRow = 1, int $limit = 100): array
    {
        // Load only the necessary data to save memory
        // We can't easily load partial file with PhpSpreadsheet without custom filters
        // But for headers/metadata we assume it's at the top.
        
        // Use a read filter to only read the top rows
        $filter = new class($startRow, $limit) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            private int $startRow;
            private int $endRow;

            public function __construct($startRow, $limit) {
                $this->startRow = $startRow;
                $this->endRow = $startRow + $limit;
            }

            public function readCell(string $column, int $row, string $worksheetName = ''): bool {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        };

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadFilter($filter);
        $reader->setReadDataOnly(false); // We need styles
        
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $styles = [];
        foreach ($sheet->getRowIterator($startRow, $startRow + $limit) as $row) {
            $rowIndex = $row->getRowIndex();
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            
            foreach ($cellIterator as $cell) {
                $colIndex = $cell->getColumn();
                $style = $sheet->getStyle($colIndex . $rowIndex);
                $font = $style->getFont();
                
                $styles[$rowIndex][$colIndex] = [
                    'is_bold' => $font->getBold(),
                    'is_italic' => $font->getItalic(),
                    'color' => $font->getColor()->getARGB(),
                    'background' => $style->getFill()->getStartColor()->getARGB(),
                    'size' => $font->getSize(),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $styles;
    }
}
