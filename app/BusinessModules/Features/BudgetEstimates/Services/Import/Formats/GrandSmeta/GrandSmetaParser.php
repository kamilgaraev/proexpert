<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GrandSmetaParser implements EstimateImportParserInterface, StreamParserInterface
{
    private StatefulGrandSmetaProcessor $processor;

    public function __construct(StatefulGrandSmetaProcessor $processor)
    {
        $this->processor = $processor;
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xls', 'xlsx', 'xlsm', 'xml'], true);
    }

    public function validateFile(string $filePath): bool
    {
        // Validation is actually handled by GrandSmetaHandler before this parser is used
        return true;
    }

    public function detectStructure(string $filePath): array
    {
        // Detection is handled in GrandSmetaHandler prior to calling parser
        // We will just return a basic structure to satisfy the interface.
        // It's mostly not used since GrandSmetaHandler handles the mapping.
        return [];
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        return [];
    }

    public function getHeaderCandidates(): array
    {
        return [];
    }

    public function getStream(string $filePath, array $options = [], ?\Closure $onProgress = null): \Generator
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $mapping = $options['column_mapping'] ?? [];
        $headerRow = $options['header_row'] ?? 1;
        $totalRows = max(1, $sheet->getHighestRow() - $headerRow);

        Log::info('[GrandSmetaParser] Starting parsing', [
            'header_row' => $headerRow,
        ]);

        $this->processor->reset();

        $rawRowIndex = 0;
        foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[$cell->getColumn()] = $cell->getCalculatedValue();
            }

            if (empty(array_filter($rowData))) {
                $rawRowIndex++;
                continue;
            }

            $this->processor->processRow($rowData, $mapping, $row->getRowIndex());
            $rawRowIndex++;

            // Уведомляем о прогрессе каждые 500 строк
            if ($onProgress && $rawRowIndex % 500 === 0) {
                $onProgress($rawRowIndex, $totalRows);
            }
        }

        $result = $this->processor->getResult();
        
        $allRows = array_merge($result['items'], $result['sections']);
        usort($allRows, fn($a, $b) => $a->rowNumber <=> $b->rowNumber);

        foreach ($allRows as $row) {
            yield $row;
        }
    }

    public function getPreview(string $filePath, int $limit = 5, array $options = []): array
    {
        $generator = $this->getStream($filePath, $options);
        $result = [];
        $count = 0;

        foreach ($generator as $item) {
            $result[] = $item;
            $count++;
            if ($count >= $limit) break;
        }

        return $result;
    }

    public function getRawSampleRows(string $filePath, array $options = [], int $limit = 5): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            $headerRow = $options['header_row'] ?? 0;
            $samples = [];
            $currentRow = $headerRow + 1; // Show numbering row and actual data
            $maxRow = min($headerRow + 20, $sheet->getHighestRow()); 
            
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            while (count($samples) < $limit && $currentRow <= $maxRow) {
                $rowData = [];
                $hasData = false;
                
                for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cell = $sheet->getCell($colLetter . $currentRow);
                    $value = $cell->getCalculatedValue();
                    
                    if ($value !== null && trim((string)$value) !== '') {
                        $hasData = true;
                    }
                    $rowData[] = $value; 
                }
                
                if ($hasData) {
                    $samples[] = $rowData;
                }
                $currentRow++;
            }
            
            return $samples;
        } catch (\Exception $e) {
            Log::error('[GrandSmetaParser] Failed to get sample rows', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function parse(string $filePath): \App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO|\Generator
    {
        return $this->getStream($filePath);
    }

    public function getTotalRows(string $filePath, array $options = []): int
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $headerRow = (int) ($options['header_row'] ?? 1);
            return max(0, $sheet->getHighestRow() - $headerRow);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getSupportedExtensions(): array
    {
        return ['xls', 'xlsx', 'xlsm', 'xml'];
    }

    public function readContent(string $filePath, int $maxRows = 100)
    {
        return IOFactory::load($filePath);
    }

    public function getFooterData(): array
    {
        return $this->processor->getResult()['footer'] ?? [];
    }
}
