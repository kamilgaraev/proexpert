<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class LocalEstimateCSVParser implements EstimateImportParserInterface
{
    private string $detectedEncoding = '';
    private string $detectedDelimiter = '';

    public function parse(string $filePath): EstimateImportDTO
    {
        $structure = $this->detectStructure($filePath);
        
        $rows = $this->extractRows($filePath, $structure);
        
        $sections = [];
        $items = [];
        
        foreach ($rows as $row) {
            if ($row->isSection) {
                $sections[] = $row->toArray();
            } else {
                $items[] = $row->toArray();
            }
        }
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'csv',
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => 0,
                'total_quantity' => 0,
                'items_count' => count($items),
            ],
            metadata: [
                'encoding' => $this->detectedEncoding,
                'delimiter' => $this->detectedDelimiter,
            ]
        );
    }

    public function detectStructure(string $filePath): array
    {
        // Определяем кодировку
        $this->detectedEncoding = $this->detectEncoding($filePath);
        
        // Определяем разделитель
        $this->detectedDelimiter = $this->detectDelimiter($filePath);
        
        Log::info('[CSVParser] Detected file properties', [
            'encoding' => $this->detectedEncoding,
            'delimiter' => $this->detectedDelimiter,
        ]);
        
        // Читаем первые строки для определения заголовков
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \Exception('Cannot open file: ' . $filePath);
        }
        
        $firstRows = [];
        for ($i = 0; $i < 10 && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($line !== false) {
                $line = $this->convertEncoding($line);
                $firstRows[] = str_getcsv($line, $this->detectedDelimiter);
            }
        }
        fclose($handle);
        
        // Простой алгоритм: первая строка с наибольшим количеством колонок - заголовок
        $headerRow = 0;
        $maxColumns = 0;
        
        foreach ($firstRows as $index => $row) {
            $count = count(array_filter($row, fn($v) => !empty(trim($v))));
            if ($count > $maxColumns) {
                $maxColumns = $count;
                $headerRow = $index;
            }
        }
        
        $headers = $firstRows[$headerRow] ?? [];
        $columnMapping = $this->mapColumns($headers);
        
        return [
            'header_row' => $headerRow,
            'encoding' => $this->detectedEncoding,
            'delimiter' => $this->detectedDelimiter,
            'raw_headers' => $headers,
            'column_mapping' => $columnMapping,
            'detected_columns' => $this->formatDetectedColumns($headers, $columnMapping),
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            return false;
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }
        
        $line = fgets($handle);
        fclose($handle);
        
        return $line !== false && !empty(trim($line));
    }

    public function getSupportedExtensions(): array
    {
        return ['csv'];
    }

    public function getHeaderCandidates(): array
    {
        // CSV обычно имеет фиксированную структуру
        return [];
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        // Простая реализация для CSV
        $structure = $this->detectStructure($filePath);
        return $structure;
    }

    /**
     * Определяет кодировку файла
     */
    private function detectEncoding(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return 'UTF-8';
        }
        
        // Читаем первые 8KB для определения кодировки
        $sample = fread($handle, 8192);
        fclose($handle);
        
        // Проверяем BOM
        if (substr($sample, 0, 3) === "\xEF\xBB\xBF") {
            return 'UTF-8';
        }
        
        // Определяем кодировку
        $encoding = mb_detect_encoding($sample, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'CP1251'], true);
        
        return $encoding ?: 'UTF-8';
    }

    /**
     * Определяет разделитель CSV
     */
    private function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ',';
        }
        
        $firstLine = fgets($handle);
        fclose($handle);
        
        if ($firstLine === false) {
            return ',';
        }
        
        // Конвертируем в UTF-8
        $firstLine = $this->convertEncoding($firstLine);
        
        // Проверяем различные разделители
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];
        
        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }
        
        arsort($counts);
        $bestDelimiter = array_key_first($counts);
        
        Log::debug('[CSVParser] Delimiter detection', [
            'counts' => $counts,
            'selected' => $bestDelimiter,
        ]);
        
        return $bestDelimiter;
    }

    /**
     * Конвертирует строку в UTF-8
     */
    private function convertEncoding(string $text): string
    {
        if (empty($this->detectedEncoding) || $this->detectedEncoding === 'UTF-8') {
            return $text;
        }
        
        return mb_convert_encoding($text, 'UTF-8', $this->detectedEncoding);
    }

    /**
     * Извлекает строки из CSV
     */
    private function extractRows(string $filePath, array $structure): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \Exception('Cannot open file: ' . $filePath);
        }
        
        $rows = [];
        $lineNumber = 0;
        $headerRow = $structure['header_row'];
        $columnMapping = $structure['column_mapping'];
        
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            
            // Пропускаем строки до заголовка и сам заголовок
            if ($lineNumber <= $headerRow + 1) {
                continue;
            }
            
            $line = $this->convertEncoding($line);
            $csvRow = str_getcsv($line, $this->detectedDelimiter);
            
            $rowData = $this->mapRowData($csvRow, $columnMapping);
            
            if ($this->isEmptyRow($rowData)) {
                continue;
            }
            
            $row = new EstimateImportRowDTO(
                rowNumber: $lineNumber,
                sectionNumber: $rowData['section_number'] ?? null,
                itemName: $rowData['name'] ?? '',
                unit: $rowData['unit'] ?? null,
                quantity: $rowData['quantity'] ?? null,
                unitPrice: $rowData['unit_price'] ?? null,
                code: $rowData['code'] ?? null,
                isSection: $this->isSection($rowData),
                level: $this->calculateSectionLevel($rowData['section_number'] ?? null),
                sectionPath: null,
            );
            
            $rows[] = $row;
        }
        
        fclose($handle);
        
        return $rows;
    }

    /**
     * Мапит данные строки CSV на поля
     */
    private function mapRowData(array $csvRow, array $columnMapping): array
    {
        $rowData = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'unit_price' => null,
            'code' => null,
        ];
        
        foreach ($columnMapping as $field => $columnIndex) {
            if ($columnIndex !== null && isset($csvRow[$columnIndex])) {
                $value = trim($csvRow[$columnIndex]);
                
                // Конвертируем числа
                if (in_array($field, ['quantity', 'unit_price']) && !empty($value)) {
                    $value = $this->parseNumber($value);
                }
                
                $rowData[$field] = $value;
            }
        }
        
        return $rowData;
    }

    /**
     * Мапит колонки по заголовкам
     */
    private function mapColumns(array $headers): array
    {
        $mapping = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'unit_price' => null,
            'code' => null,
        ];
        
        $keywords = [
            'section_number' => ['№', 'номер', 'п/п', 'n'],
            'name' => ['наименование', 'название', 'работа', 'позиция'],
            'unit' => ['ед.изм', 'единица', 'ед', 'измерение'],
            'quantity' => ['количество', 'кол-во', 'объем', 'кол'],
            'unit_price' => ['цена', 'стоимость', 'расценка'],
            'code' => ['код', 'шифр', 'обоснование'],
        ];
        
        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim($header));
            
            foreach ($keywords as $field => $keywordList) {
                if ($mapping[$field] === null) {
                    foreach ($keywordList as $keyword) {
                        if (str_contains($normalized, $keyword)) {
                            $mapping[$field] = $index;
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $mapping;
    }

    /**
     * Форматирует detected_columns для API
     */
    private function formatDetectedColumns(array $headers, array $columnMapping): array
    {
        $detected = [];
        $reverseMapping = array_flip(array_filter($columnMapping));
        
        foreach ($headers as $index => $header) {
            $field = $reverseMapping[$index] ?? null;
            
            $detected[$index] = [
                'field' => $field,
                'header' => $header,
                'confidence' => $field ? 0.8 : 0.0,
            ];
        }
        
        return $detected;
    }

    /**
     * Парсит число из строки
     */
    private function parseNumber(string $value): float
    {
        // Убираем пробелы
        $value = str_replace([' ', "\xC2\xA0"], '', $value); // Regular space and non-breaking space
        
        // Заменяем запятую на точку
        $value = str_replace(',', '.', $value);
        
        return (float) $value;
    }

    /**
     * Проверяет пустая ли строка
     */
    private function isEmptyRow(array $rowData): bool
    {
        return empty(array_filter($rowData, fn($v) => $v !== null && $v !== ''));
    }

    /**
     * Определяет является ли строка разделом
     */
    private function isSection(array $rowData): bool
    {
        $hasName = !empty($rowData['name']);
        $hasQuantity = !empty($rowData['quantity']) && $rowData['quantity'] > 0;
        $hasPrice = !empty($rowData['unit_price']) && $rowData['unit_price'] > 0;
        
        if (!$hasName) {
            return false;
        }
        
        if ($hasQuantity && $hasPrice) {
            return false;
        }
        
        return true;
    }

    /**
     * Вычисляет уровень раздела
     */
    private function calculateSectionLevel(?string $sectionNumber): int
    {
        if (empty($sectionNumber)) {
            return 0;
        }
        
        $normalized = rtrim($sectionNumber, '.');
        
        if (!preg_match('/^\d+(\.\d+)*$/', $normalized)) {
            return 0;
        }
        
        return substr_count($normalized, '.');
    }
}

