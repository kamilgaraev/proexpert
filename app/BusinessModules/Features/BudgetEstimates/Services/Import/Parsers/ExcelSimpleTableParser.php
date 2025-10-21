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
        // Увеличиваем до 50 строк для поиска заголовков (локальные сметы имеют много служебной информации)
        $maxRow = min($sheet->getHighestRow(), 50);
        
        Log::info('[ExcelParser] Detecting header row', [
            'max_row' => $maxRow,
            'highest_row' => $sheet->getHighestRow(),
        ]);
        
        // Собираем кандидатов с оценкой (scoring system)
        $candidates = [];
        $attemptedRows = [];
        
        for ($row = 1; $row <= $maxRow; $row++) {
            $rowData = [];
            $rowCells = []; // Сохраняем оригинальные значения для логирования
            
            // Детальное логирование для строк 30-40 (где должны быть заголовки)
            $isTargetRange = ($row >= 30 && $row <= 40);
            
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
                '№ п/п',
                'коэффициент',
                'всего',
                'индекс',
                'базисн', // базисных ценах
                'текущ' // текущих ценах
            ];
            
            // Исключаем строки со служебной информацией
            $hasServiceInfo = false;
            $hasDocumentTitle = false;
            
            foreach ($rowData as $cellValue) {
                // Служебная информация о программе и приказах
                if (
                    str_contains($cellValue, 'приказ') ||
                    str_contains($cellValue, 'минстрой') ||
                    str_contains($cellValue, 'гранд-смета') ||
                    str_contains($cellValue, 'версия') ||
                    str_contains($cellValue, 'программного продукта') ||
                    str_contains($cellValue, 'редакции сметных')
                ) {
                    $hasServiceInfo = true;
                    break;
                }
                
                // Заголовки документа (обычно одна большая ячейка с названием)
                if (
                    str_contains($cellValue, 'локальный сметный') ||
                    str_contains($cellValue, 'локальная смета') ||
                    str_contains($cellValue, 'объектная смета') ||
                    str_contains($cellValue, 'сводная смета') ||
                    (str_contains($cellValue, 'смета') && str_contains($cellValue, 'расчет'))
                ) {
                    $hasDocumentTitle = true;
                }
                
                // Служебная информация (основание, составлен и т.д.)
                // Но НЕ строки заголовков таблицы!
                // ВАЖНО: "обоснование" (заголовок) != "основание" (служебная инфо)
                if (
                    str_contains($cellValue, 'составлен') ||
                    (str_contains($cellValue, 'основание') && !str_contains($cellValue, 'обоснование')) ||
                    (str_contains($cellValue, 'проектная') && !str_contains($cellValue, 'п/п')) ||
                    str_contains($cellValue, 'техническая документация') ||
                    str_contains($cellValue, 'общестроительные работы') ||
                    str_contains($cellValue, 'в том числе')
                ) {
                    $hasServiceInfo = true;
                }
                
                // Описания в скобках (обычно пояснения, а не заголовки)
                if (preg_match('/^\(.+\)$/', $cellValue)) {
                    $hasServiceInfo = true;
                }
            }
            
            if ($hasServiceInfo || $hasDocumentTitle) {
                $attemptedRows[] = [
                    'row' => $row,
                    'reason' => $hasServiceInfo ? 'service_info' : 'document_title',
                    'sample' => array_slice($rowData, 0, 3),
                ];
                
                if ($isTargetRange) {
                    Log::debug('[ExcelParser] Target range row rejected (filters)', [
                        'row' => $row,
                        'reason' => $hasServiceInfo ? 'service_info' : 'document_title',
                        'sample' => array_slice($rowData, 0, 2),
                    ]);
                }
                
                continue; // Пропускаем служебную информацию и заголовки документа
            }
            
            // Считаем количество КОЛОНОК с ключевыми словами
            $columnsWithKeywords = 0;
            $matchedKeywords = [];
            $uniqueKeywords = [];
            
            foreach ($rowCells as $col => $cellValue) {
                $hasKeywordInThisColumn = false;
                
                foreach ($requiredKeywords as $keyword) {
                    if (str_contains(mb_strtolower($cellValue), $keyword)) {
                        $hasKeywordInThisColumn = true;
                        $matchedKeywords[] = "$col:$keyword";
                        
                        // Собираем уникальные ключевые слова
                        if (!in_array($keyword, $uniqueKeywords)) {
                            $uniqueKeywords[] = $keyword;
                        }
                        break; // Одно ключевое слово на колонку достаточно
                    }
                }
                
                if ($hasKeywordInThisColumn) {
                    $columnsWithKeywords++;
                }
            }
            
            $matchCount = $columnsWithKeywords;
            
            // Динамический порог: если много колонок - можно с меньшим количеством совпадений
            $requiredMatches = count($rowCells) >= 6 ? 2 : 3;
            
            if ($isTargetRange && $matchCount > 0) {
                Log::debug('[ExcelParser] Target range row analyzed', [
                    'row' => $row,
                    'match_count' => $matchCount,
                    'required' => $requiredMatches,
                    'unique_keywords' => $uniqueKeywords,
                    'filled_columns' => count($rowCells),
                    'passed' => $matchCount >= $requiredMatches,
                ]);
            }
            
            // Для российских смет достаточно 2-3 совпадений (у них сложная структура заголовков)
            if ($matchCount >= $requiredMatches) {
                // КРИТИЧНО: Если только 1 колонка заполнена - это НЕ заголовки таблицы
                if (count($rowCells) <= 1) {
                    Log::debug('[ExcelParser] Candidate rejected: only 1 column', [
                        'row' => $row,
                        'cell' => $rowCells,
                    ]);
                    continue;
                }
                
                // Вычисляем score для этой строки
                $score = $this->scoreHeaderCandidate($sheet, $row, $matchCount, $matchedKeywords, $rowCells);
                
                $candidates[] = [
                    'row' => $row,
                    'score' => $score,
                    'match_count' => $matchCount,
                    'matched_keywords' => $matchedKeywords,
                    'cells' => $rowCells,
                ];
                
                Log::debug('[ExcelParser] Header candidate found', [
                    'row' => $row,
                    'score' => $score,
                    'matches' => $matchCount,
                    'keywords' => $matchedKeywords,
                    'filled_columns' => count($rowCells),
                ]);
            }
        }
        
        // Выбираем лучшего кандидата
        if (empty($candidates)) {
            Log::error('[ExcelParser] No header candidates found', [
                'searched_rows' => $maxRow,
                'attempted_rows' => count($attemptedRows),
                'rejected_samples' => array_slice($attemptedRows, 0, 5),
            ]);
            return null;
        }
        
        // Сортируем по score (от большего к меньшему)
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        $bestCandidate = $candidates[0];
        
        // FALLBACK: Если лучший кандидат имеет очень низкий score, ищем строку с максимумом колонок
        if ($bestCandidate['score'] < 50) {
            Log::warning('[ExcelParser] Best candidate has low score, trying fallback', [
                'best_score' => $bestCandidate['score'],
                'best_row' => $bestCandidate['row'],
            ]);
            
            // Ищем строку с максимальным количеством колонок в диапазоне 20-40
            $fallbackCandidate = null;
            $maxColumns = 0;
            
            foreach ($candidates as $candidate) {
                $row = $candidate['row'];
                $columnsCount = count($candidate['cells']);
                
                if ($row >= 20 && $row <= 40 && $columnsCount > $maxColumns) {
                    $maxColumns = $columnsCount;
                    $fallbackCandidate = $candidate;
                }
            }
            
            if ($fallbackCandidate && $maxColumns >= 5) {
                Log::info('[ExcelParser] Fallback candidate selected', [
                    'row' => $fallbackCandidate['row'],
                    'columns' => $maxColumns,
                ]);
                $bestCandidate = $fallbackCandidate;
            }
        }
        
        Log::info('[ExcelParser] Best header candidate selected', [
            'row' => $bestCandidate['row'],
            'score' => $bestCandidate['score'],
            'filled_columns' => count($bestCandidate['cells']),
            'total_candidates' => count($candidates),
            'all_candidates' => array_map(fn($c) => [
                'row' => $c['row'], 
                'score' => $c['score'],
                'columns' => count($c['cells'])
            ], array_slice($candidates, 0, 5)),
        ]);
        
        return $bestCandidate['row'];
    }

    private function scoreHeaderCandidate(Worksheet $sheet, int $row, int $matchCount, array $matchedKeywords, array $rowCells): float
    {
        $score = 0.0;
        $highestCol = $sheet->getHighestColumn();
        $filledColumns = count($rowCells);
        
        // 1. Базовый score за совпадение ключевых слов (10 баллов за колонку)
        $score += $matchCount * 10;
        
        // 1.1. Бонус за РАЗНООБРАЗИЕ ключевых слов (не дубликаты)
        // Если в 9 колонках 9 раз слово "работ" - это хуже, чем 5 разных слов
        $uniqueKeywordsCount = count(array_unique($matchedKeywords));
        if ($uniqueKeywordsCount >= 5) {
            $score += 40; // Много разных ключевых слов = отличные заголовки
        } elseif ($uniqueKeywordsCount >= 3) {
            $score += 20; // Приемлемое разнообразие
        } elseif ($uniqueKeywordsCount <= 2 && $matchCount > 5) {
            $score -= 30; // Мало уникальных слов при многих колонках = дубликаты
        }
        
        // 2. Бонус за позицию в файле (заголовки обычно после 15-20 строки в сметах)
        if ($row >= 15 && $row <= 40) {
            $score += 50; // Оптимальная позиция
        } elseif ($row >= 10 && $row < 15) {
            $score += 20; // Допустимая позиция
        } elseif ($row < 10) {
            $score -= 30; // Слишком рано - скорее всего служебная информация
        }
        
        // 3. Количество заполненных колонок (заголовки таблицы имеют 5-12 колонок)
        if ($filledColumns >= 6 && $filledColumns <= 12) {
            $score += 30; // Идеальное количество колонок
        } elseif ($filledColumns >= 4 && $filledColumns < 6) {
            $score += 10; // Приемлемо
        } elseif ($filledColumns > 12) {
            $score -= 20; // Слишком много - скорее всего не заголовки
        } elseif ($filledColumns < 4) {
            $score -= 40; // Слишком мало
        }
        
        // 4. Длина текста в ячейках (заголовки обычно короткие)
        $avgLength = 0;
        $shortCellsCount = 0;
        foreach ($rowCells as $cellValue) {
            $len = mb_strlen($cellValue);
            $avgLength += $len;
            if ($len > 5 && $len < 50) { // Нормальная длина для заголовка
                $shortCellsCount++;
            }
        }
        $avgLength = $filledColumns > 0 ? $avgLength / $filledColumns : 0;
        
        if ($avgLength > 10 && $avgLength < 40) {
            $score += 20; // Оптимальная длина заголовков
        } elseif ($avgLength > 100) {
            $score -= 30; // Слишком длинные тексты - скорее всего не заголовки
        }
        
        // 5. Проверка на специфичные ключевые слова заголовков
        $specificHeaderKeywords = ['п/п', '№', 'единица', 'количество', 'обоснование'];
        $specificMatches = 0;
        foreach ($matchedKeywords as $keyword) {
            if (in_array($keyword, $specificHeaderKeywords)) {
                $specificMatches++;
            }
        }
        $score += $specificMatches * 15;
        
        // 6. Проверка наличия данных после этой строки
        $hasDataAfter = $this->validateHeaderRow($sheet, $row);
        if ($hasDataAfter) {
            $score += 40; // Критически важно
        } else {
            $score -= 50; // Нет данных - скорее всего не заголовки
        }
        
        // 7. Штраф за очень длинные тексты в одной ячейке (скорее всего описание)
        foreach ($rowCells as $cellValue) {
            if (mb_strlen($cellValue) > 150) {
                $score -= 25; // Это скорее всего текстовое описание, а не заголовок
            }
        }
        
        // 8. Бонус если есть номера/индексы в колонках
        $hasNumbers = false;
        foreach ($rowCells as $col => $cellValue) {
            if (preg_match('/^\d+$/', $cellValue) || preg_match('/^[A-Z]$/', $cellValue)) {
                $hasNumbers = true;
                break;
            }
        }
        if ($hasNumbers) {
            $score -= 20; // Заголовки не должны содержать только цифры
        }
        
        // 9. КРИТИЧНО: Заголовки таблицы должны иметь несколько заполненных колонок
        // Если только 1-2 колонки заполнены - это скорее всего название раздела/блока
        if ($filledColumns <= 2) {
            $score -= 80; // Очень сильный штраф
        }
        
        // 10. Очень длинная ячейка в первой колонке часто означает название раздела
        if (isset($rowCells['A']) && mb_strlen($rowCells['A']) > 50) {
            $score -= 30;
        }
        
        // 11. Бонус за равномерное распределение текста по колонкам
        $nonEmptyCells = 0;
        foreach ($rowCells as $cellValue) {
            if (mb_strlen($cellValue) > 0) {
                $nonEmptyCells++;
            }
        }
        if ($nonEmptyCells >= 5) {
            $score += 25; // Много заполненных колонок = хорошие заголовки
        }
        
        Log::debug('[ExcelParser] Score calculated', [
            'row' => $row,
            'score' => $score,
            'filled_columns' => $filledColumns,
            'non_empty_cells' => $nonEmptyCells,
            'avg_length' => round($avgLength, 2),
            'has_data_after' => $hasDataAfter,
            'specific_matches' => $specificMatches,
        ]);
        
        return $score;
    }

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

