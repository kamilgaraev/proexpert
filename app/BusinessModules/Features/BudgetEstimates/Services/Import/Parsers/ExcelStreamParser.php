<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use Shuchkin\SimpleXLSX;
use Generator;
use RuntimeException;

class ExcelStreamParser implements StreamParserInterface
{
    public function parse(string $filePath): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['xlsx', 'xls'])) {
            throw new RuntimeException("Unsupported file extension: {$extension}. Only XLSX and XLS are supported.");
        }

        // SimpleXLSX поддерживает потоковое чтение для XLSX и XLS
        $xlsx = SimpleXLSX::parse($filePath);
        
        if (!$xlsx) {
            throw new RuntimeException("Failed to parse file: " . SimpleXLSX::parseError());
        }

        // Получаем все строки из первого листа
        foreach ($xlsx->rows() as $row) {
            yield $row;
        }
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }
}
