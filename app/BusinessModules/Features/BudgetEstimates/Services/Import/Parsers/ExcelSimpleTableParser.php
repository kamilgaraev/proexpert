<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Illuminate\Support\Facades\Log;

class ExcelSimpleTableParser implements EstimateImportParserInterface
{
    private array $columnKeywords = [
        'name' => [
            'наименование', 
            'название', 
            'работа', 
            'позиция', 
            'наименование работ',
            'наименование работ и затрат',
            'наименование работ затрат',
            'работ и затрат'
        ],
        'unit' => [
            'ед.изм', 
            'единица', 
            'ед', 
            'измерение', 
            'ед. изм',
            'единица измерения',
            'ед.изм.'
        ],
        'quantity' => [
            'количество', 
            'кол-во', 
            'объем', 
            'кол', 
            'объём',
            'кол.'
        ],
        'unit_price' => [
            'цена', 
            'стоимость', 
            'расценка', 
            'цена за ед', 
            'стоимость единицы',
            'на единицу',
            'единицу измерения',
            'текущих ценах',
            'базисных ценах'
        ],
        'code' => [
            'код', 
            'шифр', 
            'обоснование', 
            'гэсн', 
            'фер',
            'тер',
            'шифр расценки',
            'шифр нормы'
        ],
        'section_number' => [
            '№', 
            'номер', 
            '№ п/п', 
            'п/п', 
            'n',
            '№п/п'
        ],
    ];

    public function parse(string $filePath): EstimateImportDTO
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $structure = $this->detectStructure($filePath);
        $headerRow = $structure['header_row'];
        $columnMapping = $structure['column_mapping'];
        
        $rows = $this->extractRows($sheet, $headerRow + 1, $columnMapping);
        
        $sections = [];
        $items = [];
        $currentSectionPath = [];
        
        foreach ($rows as $row) {
            if ($row->isSection) {
                $sections[] = $row->toArray();
                $level = $row->level;
                $currentSectionPath = array_slice($currentSectionPath, 0, $level);
                $currentSectionPath[] = $row->sectionNumber;
            } else {
                $row->sectionPath = !empty($currentSectionPath) 
                    ? implode('.', $currentSectionPath) 
                    : null;
                $items[] = $row->toArray();
            }
        }
        
        $totals = $this->calculateTotals($items);
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'excel_simple',
            sections: $sections,
            items: $items,
            totals: $totals,
            metadata: [
                'header_row' => $headerRow,
                'total_rows' => count($rows),
                'sheet_name' => $sheet->getTitle(),
            ],
            detectedColumns: $structure['detected_columns'],
            rawHeaders: $structure['raw_headers']
        );
    }

    public function detectStructure(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $headerRow = $this->detectHeaderRow($sheet);
        
        if ($headerRow === null) {
            throw new \Exception('Не удалось определить строку с заголовками таблицы');
        }
        
        $headers = $this->extractHeaders($sheet, $headerRow);
        $columnMapping = $this->detectColumns($headers);
        
        $detectedColumns = [];
        foreach ($columnMapping as $field => $columnLetter) {
            if ($columnLetter !== null) {
                $detectedColumns[$columnLetter] = [
                    'field' => $field,
                    'header' => $headers[$columnLetter] ?? '',
                    'confidence' => $this->calculateColumnConfidence($headers[$columnLetter] ?? '', $field),
                ];
            }
        }
        
        return [
            'header_row' => $headerRow,
            'column_mapping' => $columnMapping,
            'detected_columns' => $detectedColumns,
            'raw_headers' => $headers,
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            if ($sheet->getHighestRow() < 2) {
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'xls'];
    }

    private function detectHeaderRow(Worksheet $sheet): ?int
    {
        // Увеличиваем до 50 строк для поиска заголовков (локальные сметы имеют много служебной информации)
        $maxRow = min($sheet->getHighestRow(), 50);
        
        Log::info('[ExcelParser] Detecting header row', [
            'max_row' => $maxRow,
            'highest_row' => $sheet->getHighestRow(),
        ]);
        
        for ($row = 1; $row <= $maxRow; $row++) {
            $rowData = [];
            $rowCells = []; // Сохраняем оригинальные значения для логирования
            
            foreach (range('A', $sheet->getHighestColumn()) as $col) {
                $cell = $sheet->getCell($col . $row);
                $value = $cell->getValue();
                
                // Учитываем объединенные ячейки
                if ($sheet->getCell($col . $row)->isInMergeRange()) {
                    $mergeRange = $cell->getMergeRange();
                    if ($mergeRange) {
                        // Берем значение из первой ячейки объединенного диапазона
                        $mergeStart = explode(':', $mergeRange)[0];
                        $value = $sheet->getCell($mergeStart)->getValue();
                    }
                }
                
                if ($value !== null && trim((string)$value) !== '') {
                    $normalizedValue = mb_strtolower(trim((string)$value));
                    $rowData[] = $normalizedValue;
                    $rowCells[$col] = $normalizedValue;
                }
            }
            
            // Расширенный список ключевых слов для российских локальных смет
            $requiredKeywords = [
                'наименование', 
                'количество', 
                'цена', 
                'стоимость',
                'обоснование',
                'единица',
                'измерения',
                'работ',
                'затрат',
                'ед.изм',
                'ед. изм',
                'п/п', // номер по порядку
                'коэффициент',
                'всего'
            ];
            
            $matchCount = 0;
            $matchedKeywords = [];
            
            foreach ($rowData as $cellValue) {
                foreach ($requiredKeywords as $keyword) {
                    if (str_contains($cellValue, $keyword)) {
                        $matchCount++;
                        $matchedKeywords[] = $keyword;
                        break;
                    }
                }
            }
            
            // Для российских смет достаточно 3 совпадений (у них сложная структура заголовков)
            if ($matchCount >= 3) {
                Log::info('[ExcelParser] Header row detected', [
                    'row' => $row,
                    'match_count' => $matchCount,
                    'matched_keywords' => $matchedKeywords,
                    'row_cells' => $rowCells,
                ]);
                return $row;
            }
        }
        
        Log::error('[ExcelParser] Header row not found', [
            'searched_rows' => $maxRow,
            'tip' => 'Try increasing search depth or check if file is a valid estimate',
        ]);
        
        return null;
    }

    private function extractHeaders(Worksheet $sheet, int $headerRow): array
    {
        $headers = [];
        
        // Проверяем следующую строку - может быть многострочный заголовок
        $nextRow = $headerRow + 1;
        $hasMultilineHeader = false;
        
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $currentValue = $sheet->getCell($col . $headerRow)->getValue();
            $nextValue = $sheet->getCell($col . $nextRow)->getValue();
            
            // Если в следующей строке тоже есть текст (не число), это многострочный заголовок
            if ($nextValue !== null && trim((string)$nextValue) !== '' && !is_numeric($nextValue)) {
                $hasMultilineHeader = true;
                break;
            }
        }
        
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $value = $sheet->getCell($col . $headerRow)->getValue();
            
            // Для многострочных заголовков объединяем строки
            if ($hasMultilineHeader) {
                $nextValue = $sheet->getCell($col . $nextRow)->getValue();
                if ($nextValue !== null && trim((string)$nextValue) !== '' && !is_numeric($nextValue)) {
                    $value = trim((string)$value) . ' ' . trim((string)$nextValue);
                }
            }
            
            if ($value !== null && trim((string)$value) !== '') {
                $headers[$col] = trim((string)$value);
            }
        }
        
        Log::info('[ExcelParser] Headers extracted', [
            'header_row' => $headerRow,
            'multiline' => $hasMultilineHeader,
            'headers' => $headers,
        ]);
        
        return $headers;
    }

    private function detectColumns(array $headers): array
    {
        $mapping = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'unit_price' => null,
            'code' => null,
        ];
        
        foreach ($headers as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim($headerText));
            
            foreach ($this->columnKeywords as $field => $keywords) {
                if ($mapping[$field] === null) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($normalized, $keyword)) {
                            $mapping[$field] = $columnLetter;
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $mapping;
    }

    private function calculateColumnConfidence(string $headerText, string $field): float
    {
        $normalized = mb_strtolower(trim($headerText));
        $keywords = $this->columnKeywords[$field] ?? [];
        
        $maxConfidence = 0;
        foreach ($keywords as $keyword) {
            if ($normalized === $keyword) {
                return 1.0;
            }
            
            if (str_contains($normalized, $keyword)) {
                $confidence = mb_strlen($keyword) / max(mb_strlen($normalized), 1);
                $maxConfidence = max($maxConfidence, $confidence);
            }
        }
        
        return $maxConfidence;
    }

    private function extractRows(Worksheet $sheet, int $startRow, array $columnMapping): array
    {
        $rows = [];
        $maxRow = $sheet->getHighestRow();
        
        for ($rowNum = $startRow; $rowNum <= $maxRow; $rowNum++) {
            $rowData = $this->extractRowData($sheet, $rowNum, $columnMapping);
            
            if ($this->isEmptyRow($rowData)) {
                continue;
            }
            
            $isSection = $this->isSectionRow($rowData);
            $level = $this->calculateSectionLevel($rowData['section_number']);
            
            $rows[] = new EstimateImportRowDTO(
                rowNumber: $rowNum,
                sectionNumber: $rowData['section_number'],
                itemName: $rowData['name'],
                unit: $rowData['unit'],
                quantity: $rowData['quantity'],
                unitPrice: $rowData['unit_price'],
                code: $rowData['code'],
                isSection: $isSection,
                level: $level,
                sectionPath: null,
                rawData: $rowData
            );
        }
        
        return $rows;
    }

    private function extractRowData(Worksheet $sheet, int $rowNum, array $columnMapping): array
    {
        $data = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'unit_price' => null,
            'code' => null,
        ];
        
        foreach ($columnMapping as $field => $columnLetter) {
            if ($columnLetter !== null) {
                $cell = $sheet->getCell($columnLetter . $rowNum);
                $value = $cell->getValue();
                
                if ($field === 'quantity' || $field === 'unit_price') {
                    $data[$field] = $this->parseNumericValue($value);
                } else {
                    $data[$field] = $value !== null ? trim((string)$value) : null;
                }
            }
        }
        
        return $data;
    }

    private function parseNumericValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_numeric($value)) {
            return (float)$value;
        }
        
        $cleaned = preg_replace('/[^\d.,\-]/', '', (string)$value);
        $cleaned = str_replace(',', '.', $cleaned);
        
        if (is_numeric($cleaned)) {
            return (float)$cleaned;
        }
        
        return null;
    }

    private function isEmptyRow(array $rowData): bool
    {
        $name = $rowData['name'] ?? '';
        $quantity = $rowData['quantity'] ?? null;
        $unitPrice = $rowData['unit_price'] ?? null;
        
        return empty($name) && $quantity === null && $unitPrice === null;
    }

    private function isSectionRow(array $rowData): bool
    {
        $hasQuantity = $rowData['quantity'] !== null && $rowData['quantity'] > 0;
        $hasPrice = $rowData['unit_price'] !== null && $rowData['unit_price'] > 0;
        $hasName = !empty($rowData['name']);
        
        if (!$hasName) {
            return false;
        }
        
        if ($hasQuantity && $hasPrice) {
            return false;
        }
        
        $sectionNumber = $rowData['section_number'] ?? '';
        $hasHierarchicalNumber = preg_match('/^\d+(\.\d+)*\.?$/', $sectionNumber);
        
        if ($hasHierarchicalNumber) {
            return true;
        }
        
        if (!$hasQuantity && !$hasPrice) {
            return true;
        }
        
        return false;
    }

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

    private function calculateTotals(array $items): array
    {
        $totalAmount = 0;
        $totalQuantity = 0;
        
        foreach ($items as $item) {
            $quantity = $item['quantity'] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;
            $totalAmount += $quantity * $unitPrice;
            $totalQuantity += $quantity;
        }
        
        return [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'items_count' => count($items),
        ];
    }
}

