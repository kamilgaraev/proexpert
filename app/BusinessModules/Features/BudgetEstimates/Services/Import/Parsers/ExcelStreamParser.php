<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Shuchkin\SimpleXLSX;
use Generator;
use RuntimeException;

class ExcelStreamParser implements StreamParserInterface
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
                    isSection: false, itemType: null, level: 0, sectionPath: null,
                    rawData: $row
                );
            }
            \Illuminate\Support\Facades\Log::info('[ExcelStreamParser] Finished reading stream');
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
}
