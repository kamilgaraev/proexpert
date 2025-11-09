<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Парсер для файлов РИК (Ресурсно-индексный метод)
 * 
 * Обычно это текстовые файлы или Excel с специфичной структурой РИК
 */
class RIKParser implements EstimateImportParserInterface
{
    public function parse(string $filePath): EstimateImportDTO
    {
        // TODO: Реализовать полноценный парсинг РИК
        // Пока что базовая заглушка
        
        $sections = [];
        $items = [];
        
        // Читаем файл
        if ($this->isTextFile($filePath)) {
            $content = file_get_contents($filePath);
            $items = $this->parseTextRIK($content);
        } else {
            // Для Excel используем ExcelSimpleTableParser как fallback
            $excelParser = new ExcelSimpleTableParser();
            return $excelParser->parse($filePath);
        }
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'rik',
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => 0,
                'total_quantity' => 0,
                'items_count' => count($items),
            ],
            metadata: ['estimate_type' => 'rik'],
            estimateType: 'rik',
            typeConfidence: 80.0
        );
    }

    public function detectStructure(string $filePath): array
    {
        return [
            'format' => 'rik',
            'detected_columns' => [],
            'raw_headers' => [],
            'header_row' => null,
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['txt', 'rik', 'xlsx', 'xls']);
    }

    public function getSupportedExtensions(): array
    {
        return ['txt', 'rik', 'xlsx', 'xls'];
    }

    public function getHeaderCandidates(): array
    {
        return [];
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        return $this->detectStructure($filePath);
    }

    /**
     * Читать содержимое файла для детекции типа
     */
    public function readContent(string $filePath, int $maxRows = 100)
    {
        if ($this->isTextFile($filePath)) {
            return file_get_contents($filePath);
        } else {
            // Для Excel
            $excelParser = new ExcelSimpleTableParser();
            return $excelParser->readContent($filePath, $maxRows);
        }
    }

    /**
     * Проверить, является ли файл текстовым
     */
    private function isTextFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['txt', 'rik']);
    }

    /**
     * Парсинг текстового формата РИК
     * 
     * TODO: Реализовать полноценный парсинг
     */
    private function parseTextRIK(string $content): array
    {
        $items = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Базовая логика парсинга (расширить по мере необходимости)
            $items[] = new EstimateImportRowDTO(
                name: $line,
                quantity: 0,
                unit: '',
                unitPrice: 0,
                totalPrice: 0,
                type: 'work',
                rawData: ['line' => $line]
            );
        }
        
        return $items;
    }
}

