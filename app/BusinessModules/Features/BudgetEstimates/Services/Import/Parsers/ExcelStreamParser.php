<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;



use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Shuchkin\SimpleXLSX;
use Generator;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class ExcelStreamParser implements EstimateImportParserInterface, StreamParserInterface
{
    public function getStream(string $filePath, array $options = []): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }
        
        // SimpleXLSX supports stream reading
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rowIndex = 1;
            foreach ($xlsx->readRows() as $row) {
                // Since this is a raw stream parser, we wrap raw items in DTO
                // Mapping should be handled by the pipeline/processor if using this parser
                yield new \App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO(
                    rowNumber: $rowIndex++,
                    sectionNumber: null,
                    itemName: '', // Placeholder
                    unit: null, quantity: null, unitPrice: null, code: null,
                    isSection: false, itemType: 'work', level: 0, sectionPath: null,
                    rawData: $row
                );
            }
            Log::info('[ExcelStreamParser] Finished reading stream');
        } else {
            throw new RuntimeException("Failed to parse file: " . SimpleXLSX::parseError());
        }
    }

    public function getPreview(string $filePath, int $limit = 20, array $options = []): array
    {
        $stream = $this->getStream($filePath, $options);
        $preview = [];
        foreach ($stream as $item) {
             $preview[] = $item;
             if (count($preview) >= $limit) break;
        }
        return $preview;
    }

    /**
     * Legacy parse wrapper (if needed)
     */
    public function parse(string $filePath): Generator|EstimateImportDTO
    {
        return $this->getStream($filePath);
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }

    public function detectStructure(string $filePath): array
    {
        // Simple fallback: assume first row is header
        if (!file_exists($filePath)) {
             return [
                'format' => 'excel_simple',
                'detected_columns' => [],
                'raw_headers' => [],
                'header_row' => null,
                'column_mapping' => [],
            ];
        }

        $headers = [];
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            foreach ($xlsx->readRows() as $row) {
                $headers = $row;
                break; // First row only
            }
        }

        return [
            'format' => 'excel_simple',
            'detected_columns' => [],
            'raw_headers' => $headers,
            'header_row' => 0, // 0-based index for logic, though rowNumber usually 1-based
            'column_mapping' => [],
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        return (bool) SimpleXLSX::parse($filePath);
    }

    public function getHeaderCandidates(): array
    {
        // Not supporting header detection selection for stream parser fallback
        return [];
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        return $this->detectStructure($filePath);
    }

    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'xls'];
    }

    public function readContent(string $filePath, int $maxRows = 100)
    {
        // Not implemented for stream parser, return null
        return null;
    }
}
