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

    public function getStream(string $filePath, array $options = []): \Generator
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $mapping = $options['column_mapping'] ?? [];
        $headerRow = $options['header_row'] ?? 1;

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
        return $this->getPreview($filePath, $limit, $options);
    }

    public function parse(string $filePath): \App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO|\Generator
    {
        return $this->getStream($filePath);
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
