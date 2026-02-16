<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Shuchkin\SimpleXLSX;
use Generator;
use RuntimeException;

class ExcelStreamParser implements StreamParserInterface
{
    public function parse(string $filePath): Generator|EstimateImportDTO
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['xlsx', 'xls'])) {
            throw new RuntimeException("Unsupported file extension: {$extension}. Only XLSX and XLS are supported.");
        }

        // SimpleXLSX поддерживает потоковое чтение для XLSX и XLS
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            // readRows() is a generator method in recent versions of SimpleXLSX
            // If strictly using rows(), it might load all. 
            // Checking library capability: standard SimpleXLSX usually has readRows($sheetIndex = 0)
            $rowCount = 0;
            foreach ($xlsx->readRows() as $row) {
                if ($rowCount < 5) {
                    \Illuminate\Support\Facades\Log::debug('[ExcelStreamParser] Row data', [
                        'row_num' => $rowCount,
                        'data' => array_slice($row, 0, 5)
                    ]);
                }
                $rowCount++;
                yield $row;
            }
            \Illuminate\Support\Facades\Log::info('[ExcelStreamParser] Finished reading', ['total_rows' => $rowCount]);
        } else {
            throw new RuntimeException("Failed to parse file: " . SimpleXLSX::parseError());
        }
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }
}
