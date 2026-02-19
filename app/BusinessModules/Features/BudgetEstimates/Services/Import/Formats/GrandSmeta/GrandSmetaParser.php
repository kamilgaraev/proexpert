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

    public function getStream(string $filePath, array $structure): \Generator
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $mapping = $structure['column_mapping'] ?? [];
        $headerRow = $structure['header_row'] ?? 1;

        Log::info('[GrandSmetaParser] Starting parsing', [
            'header_row' => $headerRow,
        ]);

        $this->processor->reset();

        foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                // Ensure formula calculated values are used
                $rowData[$cell->getColumn()] = $cell->getCalculatedValue();
            }

            if (empty(array_filter($rowData))) continue;

            $this->processor->processRow($rowData, $mapping, $row->getRowIndex());
        }

        $result = $this->processor->getResult();

        foreach ($result['items'] as $item) {
            yield $item;
        }
    }

    public function getPreview(string $filePath, array $structure, int $limit = 5): array
    {
        $generator = $this->getStream($filePath, $structure);
        $result = [];
        $count = 0;

        foreach ($generator as $item) {
            $result[] = $item;
            $count++;
            if ($count >= $limit) break;
        }

        return $result;
    }

    public function getRawSampleRows(string $filePath, array $structure, int $limit = 5): array
    {
        return $this->getPreview($filePath, $structure, $limit);
    }

    public function getFooterData(): array
    {
        return $this->processor->getResult()['footer'] ?? [];
    }
}
