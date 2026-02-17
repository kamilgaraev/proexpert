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
    private array $headerCandidates = [];

    public function __construct(
        private \App\BusinessModules\Features\BudgetEstimates\Services\Import\SmartMappingService $smartMappingService
    ) {}

    public function getStream(string $filePath, array $options = []): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }
        
        $headerRow = $options['header_row'] ?? null;
        
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rowIndex = 0; // SimpleXLSX rows are 0-indexed
            foreach ($xlsx->readRows() as $row) {
                // We no longer skip rows before header_row. 
                // The RowMapper and Logic will handle identifying headers/sections/items.

                // Skip completely empty rows
                if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                    $rowIndex++;
                    continue;
                }

                yield new \App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO(
                    rowNumber: $rowIndex + 1, // 1-indexed for the user
                    sectionNumber: null,
                    itemName: '', // Placeholder
                    unit: null, quantity: null, unitPrice: null, code: null,
                    isSection: false, itemType: 'work', level: 0, sectionPath: null,
                    rawData: $row
                );
                $rowIndex++;
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
        if (!file_exists($filePath)) {
             return [
                'format' => 'excel_simple',
                'detected_columns' => [],
                'raw_headers' => [],
                'header_row' => null,
                'column_mapping' => [],
            ];
        }

        $this->headerCandidates = [];
        $bestRow = 0;
        $bestScore = 0;
        $bestMapping = [];
        $bestHeaders = [];

        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rows = $xlsx->readRows();
            $maxRowsToScan = 50;
            $rowIndex = 0;

            foreach ($rows as $row) {
                if ($rowIndex >= $maxRowsToScan) break;

                $detection = $this->smartMappingService->detectMapping($row);
                $score = $this->calculateHeaderScore($detection);

                if ($score > 0) {
                    $this->headerCandidates[] = [
                        'row_index' => $rowIndex,
                        'score' => $score,
                        'headers' => $row,
                        'mapping' => $detection['mapping'],
                        'detected_columns' => $detection['detected_columns']
                    ];
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestRow = $rowIndex;
                    $bestMapping = $detection['mapping'];
                    $bestHeaders = $row;
                    $bestDetectedColumns = $detection['detected_columns'];
                }

                $rowIndex++;
            }
        }

        // Sort candidates by score
        usort($this->headerCandidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'format' => 'excel_simple',
            'detected_columns' => $bestDetectedColumns ?? [],
            'raw_headers' => $bestHeaders,
            'header_row' => $bestRow,
            'column_mapping' => $bestMapping,
        ];
    }

    private function calculateHeaderScore(array $detection): float
    {
        $mapping = $detection['mapping'];
        $mappedCount = count(array_filter($mapping));
        
        // Critical fields give more score
        $criticalFields = ['name', 'quantity', 'unit_price'];
        $score = 0;
        
        foreach ($mapping as $field => $col) {
            if ($col !== null) {
                $score += in_array($field, $criticalFields) ? 2 : 1;
            }
        }

        return (float) $score;
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
        return array_slice($this->headerCandidates, 0, 5);
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rowIndex = 0;
            foreach ($xlsx->readRows() as $row) {
                if ($rowIndex === $headerRow) {
                    $detection = $this->smartMappingService->detectMapping($row);
                    return [
                        'format' => 'excel_simple',
                        'detected_columns' => $detection['detected_columns'],
                        'raw_headers' => $row,
                        'header_row' => $rowIndex,
                        'column_mapping' => $detection['mapping'],
                    ];
                }
                $rowIndex++;
            }
        }
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
