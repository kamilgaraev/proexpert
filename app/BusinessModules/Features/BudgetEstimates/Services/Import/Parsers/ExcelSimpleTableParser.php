<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\CompositeHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors\KeywordBasedDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors\MergedCellsAwareDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors\MultilineHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors\NumericHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\MergedCellResolver;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Illuminate\Support\Facades\Log;

class ExcelSimpleTableParser implements EstimateImportParserInterface
{
    private array $headerCandidates = [];
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
            'текущем уровне цен',  // ПРИОРИТЕТ: "на единицу измерения в текущем уровне цен"
            'базисном уровне цен', // "на единицу измерения в базисном уровне цен"
            'сметная стоимость', // "Сметная стоимость, руб."
            'цена', 
            'стоимость', 
            'расценка', 
            'цена за ед', 
            'стоимость единицы',
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
        
        // Возвращаем ВСЕ колонки, даже нераспознанные
        $detectedColumns = [];
        $reverseMapping = array_flip(array_filter($columnMapping)); // field => columnLetter
        
        foreach ($headers as $columnLetter => $headerText) {
            // Ищем распознанное поле для этой колонки
            $field = $reverseMapping[$columnLetter] ?? null;
            
            if ($field) {
                // Колонка распознана
                $detectedColumns[$columnLetter] = [
                    'field' => $field,
                    'header' => $headerText,
                    'confidence' => $this->calculateColumnConfidence($headerText, $field),
                ];
            } else {
                // Колонка не распознана - возвращаем как есть
                $detectedColumns[$columnLetter] = [
                    'field' => null, // Не распознано
                    'header' => $headerText,
                    'confidence' => 0.0,
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
        // Используем новую архитектуру детекторов
        $detector = new CompositeHeaderDetector([
            new MergedCellsAwareDetector(),
            new MultilineHeaderDetector(),
            new KeywordBasedDetector($this->columnKeywords),
            new NumericHeaderDetector(),
        ]);
        
        Log::info('[ExcelParser] Detecting header row with composite detector');
        
        $candidates = $detector->detectCandidates($sheet);
        
        if (empty($candidates)) {
            Log::error('[ExcelParser] No header candidates found');
            return null;
        }
        
        // Сохраняем всех кандидатов для API
        $this->headerCandidates = [];
        foreach ($candidates as $candidate) {
            $score = $detector->scoreCandidate($candidate, ['sheet' => $sheet]);
            
            $this->headerCandidates[] = [
                'row' => $candidate['row'],
                'confidence' => round($score, 2),
                'columns_count' => $candidate['filled_columns'] ?? 0,
                'preview' => array_values(array_slice($candidate['raw_values'] ?? [], 0, 5)),
                'issues' => $this->detectIssues($candidate),
                'detectors' => $candidate['detectors'] ?? [],
            ];
        }
        
        // Сортируем кандидатов по confidence
        usort($this->headerCandidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        // Выбираем лучшего
        $best = $detector->selectBest($candidates);
        
        if (!$best) {
            Log::error('[ExcelParser] Failed to select best candidate');
            return null;
        }
        
        Log::info('[ExcelParser] Best header candidate selected', [
            'row' => $best['row'],
            'confidence' => $best['confidence'] ?? 0,
            'columns' => $best['filled_columns'] ?? 0,
            'detectors' => $best['detectors'] ?? [],
        ]);
        
        return $best['row'];
    }

    // Старый метод scoreHeaderCandidate удален - используется новая архитектура детекторов

    private function validateHeaderRow(Worksheet $sheet, int $headerRow): bool
    {
        // Проверяем 5-10 строк после потенциальных заголовков (в сметах первые строки - разделы)
        $checkRows = min(10, $sheet->getHighestRow() - $headerRow);
        
        if ($checkRows < 2) {
            return false; // Слишком мало строк после заголовков
        }
        
        $dataRowsFound = 0;
        $sectionRowsFound = 0; // Разделы/блоки (текст без чисел)
        $highestCol = $sheet->getHighestColumn();
        
        for ($i = 1; $i <= $checkRows; $i++) {
            $currentRow = $headerRow + $i;
            $hasNumericData = false;
            $hasTextData = false;
            $cellsWithData = 0;
            $serviceCells = 0;
            
            foreach (range('A', $highestCol) as $col) {
                $value = $sheet->getCell($col . $currentRow)->getValue();
                
                if ($value === null || trim((string)$value) === '') {
                    continue;
                }
                
                $cellsWithData++;
                $strValue = mb_strtolower(trim((string)$value));
                
                // Проверяем на служебную информацию
                if (
                    str_contains($strValue, 'приказ') ||
                    str_contains($strValue, 'минстрой') ||
                    str_contains($strValue, 'гранд-смета') ||
                    str_contains($strValue, 'версия') ||
                    str_contains($strValue, 'программ')
                ) {
                    $serviceCells++;
                }
                
                if (is_numeric($value)) {
                    $hasNumericData = true;
                } else {
                    $hasTextData = true;
                }
            }
            
            // Если слишком много служебной информации, это не таблица данных
            if ($serviceCells > $cellsWithData / 2) {
                Log::debug('[ExcelParser] Service info detected in row', [
                    'row' => $currentRow,
                    'service_cells' => $serviceCells,
                    'total_cells' => $cellsWithData,
                ]);
                continue;
            }
            
            // Строка с данными (текст + числа)
            if ($hasNumericData && $hasTextData && $cellsWithData >= 2) {
                $dataRowsFound++;
            }
            
            // Строка раздела/блока (только текст, например "Раздел 1. Земляные работы")
            if ($hasTextData && !$hasNumericData && $cellsWithData >= 1) {
                $sectionRowsFound++;
            }
        }
        
        // Валидная таблица: минимум 1 строка данных ИЛИ минимум 2 строки разделов
        $isValid = ($dataRowsFound >= 1) || ($sectionRowsFound >= 2);
        
        Log::debug('[ExcelParser] Header validation', [
            'header_row' => $headerRow,
            'data_rows_found' => $dataRowsFound,
            'section_rows_found' => $sectionRowsFound,
            'is_valid' => $isValid,
        ]);
        
        return $isValid;
    }

    private function extractHeaders(Worksheet $sheet, int $headerRow): array
    {
        // Используем MergedCellResolver для корректной обработки объединенных ячеек
        $resolver = new MergedCellResolver();
        $headers = $resolver->resolveHeaders($sheet, $headerRow);
        
        Log::info('[ExcelParser] Headers extracted using MergedCellResolver', [
            'header_row' => $headerRow,
            'headers_count' => count($headers),
            'sample' => array_slice($headers, 0, 10),
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
        
        if (empty($normalized)) {
            return 0.0;
        }
        
        $maxConfidence = 0;
        $matchedKeywords = 0;
        $keywordImportance = $this->getKeywordImportance($field);
        
        foreach ($keywords as $index => $keyword) {
            // Точное совпадение - максимальный confidence
            if ($normalized === $keyword) {
                return 1.0;
            }
            
            if (str_contains($normalized, $keyword)) {
                $matchedKeywords++;
                
                // Базовый confidence на основе длины ключевого слова
                $lengthRatio = mb_strlen($keyword) / max(mb_strlen($normalized), 1);
                
                // Важность ключевого слова (первые в списке - важнее)
                $importance = $keywordImportance[$index] ?? 1.0;
                
                // Позиция в тексте (начало важнее)
                $position = mb_strpos($normalized, $keyword);
                $positionBonus = ($position === 0) ? 0.2 : (($position < 10) ? 0.1 : 0);
                
                // Итоговый confidence для этого ключевого слова
                $confidence = min(
                    $lengthRatio * $importance + $positionBonus,
                    1.0
                );
                
                $maxConfidence = max($maxConfidence, $confidence);
            }
        }
        
        // Бонус если совпало несколько ключевых слов
        if ($matchedKeywords > 1) {
            $maxConfidence = min($maxConfidence + ($matchedKeywords - 1) * 0.1, 1.0);
        }
        
        // Минимум 0.8 если есть хотя бы одно совпадение с важным ключевым словом
        if ($maxConfidence > 0.5 && $matchedKeywords > 0) {
            $maxConfidence = max($maxConfidence, 0.85);
        }
        
        return $maxConfidence;
    }

    /**
     * Возвращает важность ключевых слов для поля
     * Первые в списке - самые важные
     */
    private function getKeywordImportance(string $field): array
    {
        // Веса для ключевых слов (по их позиции в массиве)
        // Первые 3 - самые важные (вес 1.2)
        // Следующие 3 - важные (вес 1.1)
        // Остальные - обычные (вес 1.0)
        
        $keywords = $this->columnKeywords[$field] ?? [];
        $importance = [];
        
        foreach ($keywords as $index => $keyword) {
            if ($index < 3) {
                $importance[$index] = 1.2; // Очень важные
            } elseif ($index < 6) {
                $importance[$index] = 1.1; // Важные
            } else {
                $importance[$index] = 1.0; // Обычные
            }
        }
        
        return $importance;
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

    /**
     * Возвращает всех кандидатов на роль заголовка
     *
     * @return array
     */
    public function getHeaderCandidates(): array
    {
        return $this->headerCandidates;
    }

    /**
     * Определяет структуру файла из указанной строки заголовков
     *
     * @param string $filePath
     * @param int $headerRow
     * @return array
     */
    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        Log::info('[ExcelParser] Detecting structure from specified row', [
            'header_row' => $headerRow,
        ]);
        
        // Используем MergedCellResolver для извлечения заголовков
        $resolver = new MergedCellResolver();
        $headers = $resolver->resolveHeaders($sheet, $headerRow);
        
        // Определяем маппинг колонок
        $columnMapping = $this->detectColumns($headers);
        
        // Формируем detected_columns
        $detectedColumns = [];
        $reverseMapping = array_flip(array_filter($columnMapping)); // field => columnLetter
        
        foreach ($headers as $columnLetter => $headerText) {
            // Ищем распознанное поле для этой колонки
            $field = $reverseMapping[$columnLetter] ?? null;
            
            if ($field) {
                // Колонка распознана
                $detectedColumns[$columnLetter] = [
                    'field' => $field,
                    'header' => $headerText,
                    'confidence' => $this->calculateColumnConfidence($headerText, $field),
                ];
            } else {
                // Колонка не распознана - возвращаем как есть
                $detectedColumns[$columnLetter] = [
                    'field' => null, // Не распознано
                    'header' => $headerText,
                    'confidence' => 0.0,
                ];
            }
        }
        
        return [
            'format' => 'excel_simple_table',
            'header_row' => $headerRow,
            'raw_headers' => $headers,
            'column_mapping' => $columnMapping,
            'detected_columns' => $detectedColumns,
            'total_rows' => $sheet->getHighestRow(),
            'total_columns' => count($headers),
        ];
    }

    /**
     * Обнаруживает проблемы в кандидате на заголовок
     *
     * @param array $candidate
     * @return array
     */
    private function detectIssues(array $candidate): array
    {
        $issues = [];
        
        // Проверка на объединенные ячейки
        if ($candidate['has_merged_cells'] ?? false) {
            $issues[] = 'merged_cells_detected';
        }
        
        // Проверка на малое количество колонок
        $filledColumns = $candidate['filled_columns'] ?? 0;
        if ($filledColumns < 5) {
            $issues[] = 'few_columns';
        }
        
        // Проверка на многострочность
        if ($candidate['is_multiline'] ?? false) {
            $issues[] = 'multiline_header';
        }
        
        // Проверка позиции
        $row = $candidate['row'] ?? 0;
        if ($row < 10) {
            $issues[] = 'early_position';
        } elseif ($row > 50) {
            $issues[] = 'late_position';
        }
        
        return $issues;
    }
}

