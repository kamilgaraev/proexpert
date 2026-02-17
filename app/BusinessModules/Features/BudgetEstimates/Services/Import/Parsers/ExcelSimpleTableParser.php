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
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateItemTypeDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\AISectionDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Mapping\AIColumnMapper;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategy\AIPriceStrategyService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategy\AIRowClassifierService;
use App\BusinessModules\Features\BudgetEstimates\Enums\PriceStrategyEnum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xml;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Illuminate\Support\Facades\Log;

class ExcelSimpleTableParser implements EstimateImportParserInterface
{
    private EstimateItemTypeDetector $typeDetector;
    private NormativeCodeService $codeService;
    private ?AISectionDetector $aiSectionDetector;
    private ?AIColumnMapper $aiColumnMapper;
    private ?AIPriceStrategyService $priceStrategyService;
    private ?AIRowClassifierService $rowClassifierService;
    private array $headerCandidates = [];
    private bool $useAI = true; // Флаг для включения/отключения AI
    private string $priceStrategy = PriceStrategyEnum::DEFAULT; // Текущая стратегия цен
    private array $aiRowTypes = []; // Кеш типов строк от AI
    
    // ==========================================
    // CONSTANTS: ROW TYPES & STATES
    // ==========================================
    
    private const ROW_TYPE_ITEM = 'item';
    private const ROW_TYPE_SECTION = 'section';
    private const ROW_TYPE_SUMMARY = 'summary';
    private const ROW_TYPE_IGNORE = 'ignore';
    
    private const STATE_SEARCHING = 'searching';
    private const STATE_IN_SECTION = 'in_section';
    private const STATE_SUMMARY_MODE = 'summary_mode';
    
    public function __construct(
        ?AISectionDetector $aiSectionDetector = null,
        ?AIColumnMapper $aiColumnMapper = null,
        ?AIPriceStrategyService $priceStrategyService = null,
        ?AIRowClassifierService $rowClassifierService = null
    ) {
        $this->typeDetector = new EstimateItemTypeDetector();
        $this->codeService = new NormativeCodeService();
        $this->aiSectionDetector = $aiSectionDetector;
        $this->aiColumnMapper = $aiColumnMapper;
        $this->priceStrategyService = $priceStrategyService ?? new AIPriceStrategyService();
        $this->rowClassifierService = $rowClassifierService ?? new AIRowClassifierService();
        
        // AI опционален - если не передан, работаем без него
        if ($aiSectionDetector === null || $aiColumnMapper === null) {
            // Но мы попробуем создать их, если есть возможность (или оставить как есть)
            if ($aiSectionDetector === null) {
                 // Fallback to null logic handled inside methods
            }
        }
    }

    /**
     * Читать содержимое файла для детекции типа (без полного парсинга)
     * 
     * @param string $filePath Путь к файлу
     * @param int $maxRows Максимальное количество строк для чтения
     * @return mixed Worksheet для Excel
     */
    public function readContent(string $filePath, int $maxRows = 100)
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        return $worksheet; // Возвращаем Worksheet для детекторов
    }
    
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
            'ед.изм.',
            'е д и н и ц а', // Разрядка
        ],
        'quantity' => [
            'количество на единицу',
            'количество', 
            'кол-во', 
            'объем', 
            'кол', 
            'объём',
            'кол.'
        ],
        'quantity_coefficient' => [
            'коэффициенты',
            'коэф.',
            'к-т',
        ],
        'quantity_total' => [
            'всего с учетом коэффициентов',
            'количество всего',
            'итого количество',
        ],
        'base_unit_price' => [
            'базисном уровне цен на единицу',
            'на единицу измерения в базисном',
            'в базисном уровне',
            'базисный уровень',
        ],
        'price_index' => [
            'индекс',
            'индекс пересчета',
        ],
        'current_unit_price' => [
            'текущем уровне цен на единицу',
            'на единицу измерения в текущем',
            'в текущем уровне',
            'текущий уровень',
        ],
        'price_coefficient' => [
            'коэффициенты стоимость',
            'коэф. стоимость',
        ],
        'current_total_amount' => [
            'всего в текущем уровне',
            'всего текущий',
            'сметная стоимость всего',
        ],
        'unit_price' => [
            'сметная стоимость',
            'цена', 
            'стоимость', 
            'расценка', 
            'цена за ед', 
            'стоимость единицы',
        ],
        'code' => [
            'код', 
            'шифр', 
            'обоснование', 
            'гэсн', 
            'фер',
            'тер',
            'фсбц',
            'фсбцс',
            'шифр расценки',
            'шифр нормы',
            'код нормы',
            'нормативы',
            'код норматива',
            'расценка'
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
        
        // ✅ Включаем вычисление формул
        $spreadsheet->getActiveSheet()->setShowGridlines(false);
        \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableBranchPruning();
        
        $structure = $this->detectStructure($filePath);
        $headerRow = $structure['header_row'];
        $columnMapping = $structure['column_mapping'];
        
        // 🧠 AI PRICE CALIBRATION
        // Определяем стратегию извлечения цен перед парсингом строк
        $this->detectPriceStrategy($sheet, $headerRow, $columnMapping);
        
        // 🧠 AI ROW CLASSIFICATION (PRE-PROCESS)
        // Запускаем пакетную классификацию строк через AI
        if ($this->useAI && $this->rowClassifierService) {
            $this->classifyRowsWithAI($sheet, $headerRow + 1, $columnMapping);
        }

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
                
                Log::info('[ExcelParser] Раздел обнаружен', [
                    'row' => $row->rowNumber,
                    'section_number' => $row->sectionNumber,
                    'name' => substr($row->itemName, 0, 100),
                    'level' => $level,
                ]);
            } else {
                $row->sectionPath = !empty($currentSectionPath) 
                    ? implode('.', $currentSectionPath) 
                    : null;
                $items[] = $row->toArray();
            }
        }
        
        Log::info('[ExcelParser] Parsing completed', [
            'total_rows_processed' => count($rows),
            'sections_count' => count($sections),
            'items_count' => count($items),
        ]);
        
        // ⭐ АВТОМАТИЧЕСКОЕ СОЗДАНИЕ РАЗДЕЛОВ (если их нет)
        $autoGeneratedSections = false;
        if (empty($sections) && !empty($items)) {
            Log::info('[ExcelParser] Разделов нет - создаем автоматически');
            $autoSections = $this->createDefaultSections($items);
            $sections = $autoSections['sections'];
            $items = $autoSections['items'];
            $autoGeneratedSections = $autoSections['auto_generated_sections'] ?? true;
            
            Log::info('[ExcelParser] Автоматические разделы созданы', [
                'sections_count' => count($sections),
                'items_with_sections' => count(array_filter($items, fn($i) => !empty($i['section_path']))),
            ]);
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
                'auto_generated_sections' => $autoGeneratedSections,
            ],
            detectedColumns: $structure['detected_columns'],
            rawHeaders: $structure['raw_headers']
        );
    }

    public function detectStructure(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        // ✅ Включаем вычисление формул
        \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableBranchPruning();
        
        $headerRow = $this->detectHeaderRow($sheet);
        
        if ($headerRow === null) {
            return [
                'header_row' => null,
                'column_mapping' => [],
                'detected_columns' => [],
                'raw_headers' => [],
                'ai_suggestions' => []
            ];
        }
        
        $headers = $this->extractHeaders($sheet, $headerRow);
        $columnMapping = $this->detectColumns($headers);
        
        // 🤖 AI ENHANCEMENT: Попытка улучшить маппинг с помощью AI
        if ($this->useAI && $this->aiColumnMapper) {
            $sampleRows = $this->getSampleRowsForAI($sheet, $headerRow);
            $aiMapping = $this->aiColumnMapper->mapColumns($headers, $sampleRows);
            
            if (!empty($aiMapping['fields']) && $aiMapping['overall_confidence'] >= 0.7) {
                Log::info('[ExcelParser] AI column mapping applied', [
                    'ai_confidence' => $aiMapping['overall_confidence'],
                    'ai_fields' => array_keys($aiMapping['fields'])
                ]);
                
                // Объединяем AI результаты с существующим маппингом
                $columnMapping = $this->mergeAIMapping($columnMapping, $aiMapping);
            }
        }
        
        return [
            'header_row' => $headerRow,
            'column_mapping' => $columnMapping,
            'detected_columns' => $this->getDetectedColumnsInfo($columnMapping),
            'raw_headers' => $headers,
            'ai_suggestions' => $aiMapping['suggestions'] ?? []
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
            
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
            
            for ($colIdx = 1; $colIdx <= $highestColIndex; $colIdx++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $cell = $sheet->getCell($col . $currentRow);
                
                // ✅ Вычисляем формулы
                try {
                    $value = $cell->getCalculatedValue();
                } catch (\Exception $e) {
                    $value = $cell->getValue();
                }
                
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
            'quantity_coefficient' => null,
            'quantity_total' => null,
            'unit_price' => null,
            'base_unit_price' => null,
            'price_index' => null,
            'current_unit_price' => null,
            'price_coefficient' => null,
            'current_total_amount' => null,
            'code' => null,
        ];
        
        // 🔍 ДЕТАЛЬНОЕ ЛОГИРОВАНИЕ ЗАГОЛОВКОВ
        Log::info('[ExcelParser] Detecting columns from headers', [
            'headers_count' => count($headers),
            'headers' => $headers,
        ]);
        
        foreach ($headers as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim($headerText));
            
            foreach ($this->columnKeywords as $field => $keywords) {
                if (!isset($mapping[$field]) || $mapping[$field] === null) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($normalized, $keyword)) {
                            $mapping[$field] = $columnLetter;
                            
                            Log::debug('[ExcelParser] Column mapped', [
                                'field' => $field,
                                'column' => $columnLetter,
                                'header_text' => $headerText,
                                'matched_keyword' => $keyword,
                            ]);
                            
                            break 2;
                        }
                    }
                }
            }
        }
        
        // 🔍 ЛОГИРОВАНИЕ ФИНАЛЬНОГО MAPPING
        Log::info('[ExcelParser] Final column mapping', [
            'mapping' => $mapping,
            'name_column' => $mapping['name'],
            'code_column' => $mapping['code'],
            'unit_column' => $mapping['unit'],
            'quantity_column' => $mapping['quantity'],
            'unit_price_column' => $mapping['unit_price'],
        ]);
        
        // ⚠️ ПРЕДУПРЕЖДЕНИЯ О НЕЗАМАПЛЕННЫХ КРИТИЧНЫХ КОЛОНКАХ
        $criticalFields = ['name'];
        foreach ($criticalFields as $field) {
            if ($mapping[$field] === null) {
                Log::warning('[ExcelParser] Critical field not mapped', [
                    'field' => $field,
                    'available_headers' => $headers,
                    'keywords' => $this->columnKeywords[$field] ?? [],
                ]);
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
        $consecutiveEmptyRows = 0;
        $maxConsecutiveEmptyRows = 20; 
        
        // ==========================================
        // STATE MACHINE INITIALIZATION
        // ==========================================
        $currentState = self::STATE_SEARCHING;
        $currentSectionNumber = null;
        
        for ($rowNum = $startRow; $rowNum <= $maxRow; $rowNum++) {
            $rowData = $this->extractRowData($sheet, $rowNum, $columnMapping);
            
            // 🧹 SANITIZATION: Clean up 'unit' before classification
            if (!empty($rowData['unit'])) {
                // If unit looks like a number or is too long -> clear it
                if (preg_match('/^[\d\s\.,\n]+$/', $rowData['unit']) || mb_strlen($rowData['unit']) > 15) {
                    $rowData['unit'] = null;
                }
            }
            
            // 🗑️ EMPTY CHECK
            if ($this->isEmptyRow($rowData)) {
                $consecutiveEmptyRows++;
                if ($consecutiveEmptyRows >= $maxConsecutiveEmptyRows) {
                    Log::info("[ExcelParser] Stopped at row {$rowNum} after {$consecutiveEmptyRows} empty rows");
                    break;
                }
                continue;
            }
            $consecutiveEmptyRows = 0;
            
            // 🤖 CLASSIFICATION
            $rowType = $this->classifyRow($rowData, $rowNum);
            
            Log::debug("[ExcelParser] Row {$rowNum} classified as {$rowType}", [
                'name' => substr($rowData['name'] ?? '', 0, 30),
                'state_before' => $currentState
            ]);
            
            // ==========================================
            // STATE MACHINE LOGIC
            // ==========================================
            
            switch ($rowType) {
                case self::ROW_TYPE_IGNORE:
                    continue 2; // Skip to next row
                    
                case self::ROW_TYPE_SECTION:
                    // Create new section
                    $isSection = true;
                    $currentState = self::STATE_IN_SECTION;
                    $currentSectionNumber = $rowData['section_number'];
                    // Fallthrough to add row
                    break;
                    
                case self::ROW_TYPE_SUMMARY:
                    // Enter summary mode
                    $currentState = self::STATE_SUMMARY_MODE;
                    $isSection = true; // Summaries are stored as sections/markers in current structure
                    // In current DTO, summary rows are treated as sections with item_type=summary
                    // This matches the previous logic but with better detection
                    break;
                    
                case self::ROW_TYPE_ITEM:
                    // If we were in SUMMARY_MODE and found an item -> assume we are back in section
                    // (e.g. sometimes summaries are in the middle, or we missed a section header)
                    if ($currentState === self::STATE_SUMMARY_MODE) {
                        $currentState = self::STATE_IN_SECTION;
                        Log::info("[ExcelParser] Auto-transition from SUMMARY to IN_SECTION at row {$rowNum}");
                    }
                    $isSection = false;
                    break;
                    
                default:
                    $isSection = false;
            }
            
            $level = $this->calculateSectionLevel($rowData['section_number']);
            
            // Determine Item Type
            if ($rowType === self::ROW_TYPE_SUMMARY) {
                $itemType = 'summary';
            } elseif ($rowType === self::ROW_TYPE_SECTION) {
                $itemType = 'section'; // Or null? The DTO expects 'work'/'material' etc.
            } else {
                $itemType = $this->typeDetector->detectType(
                    $rowData['code'],
                    $rowData['name'],
                    $rowData['section_number']
                );
            }
            
            $itemName = $rowData['name'] ?? '';
            if (empty(trim($itemName)) && !empty($rowData['section_number'])) {
                $itemName = 'Раздел ' . $rowData['section_number'];
            }
            if (empty(trim($itemName))) {
                $itemName = '[Без наименования]';
            }
            
            $rows[] = new EstimateImportRowDTO(
                rowNumber: $rowNum,
                sectionNumber: $rowData['section_number'],
                itemName: $itemName,
                unit: $rowData['unit'],
                quantity: $rowData['quantity'],
                unitPrice: $rowData['unit_price'],
                code: $rowData['code'],
                isSection: $isSection,
                itemType: $itemType,
                level: $level,
                sectionPath: null,
                rawData: $rowData,
                quantityCoefficient: $rowData['quantity_coefficient'] ?? null,
                quantityTotal: $rowData['quantity_total'] ?? null,
                baseUnitPrice: $rowData['base_unit_price'] ?? null,
                priceIndex: $rowData['price_index'] ?? null,
                currentUnitPrice: $rowData['current_unit_price'] ?? null,
                priceCoefficient: $rowData['price_coefficient'] ?? null,
                currentTotalAmount: $rowData['current_total_amount'] ?? null,
                isNotAccounted: $rowData['is_not_accounted'] ?? false
            );
        }
        
        return $rows;
    }

    /**
     * Проверка на "мусорные" строки (номера колонок, обрывки)
     */
    private function isGarbageRow(array $rowData): bool
    {
        $name = trim($rowData['name'] ?? '');
        
        // 1. Если имя - просто число (1, 2, 3...)
        if (preg_match('/^\d+$/', $name) && mb_strlen($name) < 4) {
            return true;
        }
        
        // 2. Если имя слишком короткое (менее 2 символов) и нет кода
        if (mb_strlen($name) < 2 && empty($rowData['code'])) {
            return true;
        }
        
        return false;
    }

    private function extractRowData(Worksheet $sheet, int $rowNum, array $columnMapping): array
    {
        $data = [
            'section_number' => null,
            'name' => null,
            'unit' => null,
            'quantity' => null,
            'quantity_coefficient' => null,
            'quantity_total' => null,
            'unit_price' => null,
            'base_unit_price' => null,
            'price_index' => null,
            'current_unit_price' => null,
            'price_coefficient' => null,
            'current_total_amount' => null,
            'code' => null,
            'is_not_accounted' => false, // ⭐ Флаг "не учтенного" материала
            'style' => [], // ⭐ Стиль строки
        ];
        
        // Извлекаем стиль из колонки с названием (или A, если названия нет в маппинге)
        $styleColumn = $columnMapping['name'] ?? 'A';
        try {
            $styleObject = $sheet->getStyle($styleColumn . $rowNum);
            $font = $styleObject->getFont();
            $cell = $sheet->getCell($styleColumn . $rowNum);
            
            $data['style'] = [
                'is_bold' => $font->getBold(),
                'is_italic' => $font->getItalic(),
                'size' => $font->getSize(),
                'is_merged' => $cell->isInMergeRange(),
                'indent' => $styleObject->getAlignment()->getIndent(), // Отступ важен для иерархии
            ];
        } catch (\Exception $e) {
            $data['style'] = [];
        }

        $numericFields = [
            'quantity',
            'quantity_coefficient',
            'quantity_total',
            'unit_price',
            'base_unit_price',
            'price_index',
            'current_unit_price',
            'price_coefficient',
            'current_total_amount',
        ];
        
        // ⭐ Проверка буквы "Н" в колонке A (не учтенный материал)
        $cellA = $sheet->getCell('A' . $rowNum);
        $valueA = trim((string)$cellA->getValue());
        if (mb_strtoupper($valueA) === 'Н') {
            $data['is_not_accounted'] = true;
        }
        
        foreach ($columnMapping as $field => $columnLetter) {
            if ($columnLetter !== null) {
                $cell = $sheet->getCell($columnLetter . $rowNum);
                
                // 🔧 ИСПРАВЛЕНИЕ: Вычисляем формулы!
                try {
                    // Пытаемся получить вычисленное значение формулы
                    $value = $cell->getCalculatedValue();
                } catch (\Exception $e) {
                    // Если не получилось (формула с ошибкой), берем обычное значение
                    $value = $cell->getValue();
                }
                
                if (in_array($field, $numericFields)) {
                    $data[$field] = $this->parseNumericValue($value);
                } else {
                    $val = $value !== null ? trim((string)$value) : null;
                    
                    // 🔧 FIX: Если поле 'unit', но значение похоже на число (цена/трудозатраты) или слишком длинное -> игнорируем
                    if ($field === 'unit' && $val) {
                        // Если значение содержит только цифры, точки, запятые и переносы строк - это скорее всего число
                        if (preg_match('/^[\d\s\.,\n]+$/', $val)) {
                            $val = null; 
                        }
                        // Если значение слишком длинное (> 20 символов), это скорее всего не единица измерения
                        elseif (mb_strlen($val) > 20) {
                            $val = null;
                        }
                    }
                    
                    // 🔧 FIX: Очистка названия от метаданных (ИНДЕКС, НР, СП и т.д.)
                    if ($field === 'name' && $val) {
                         $pruningPatterns = [
                             '/ИНДЕКС К ПОЗИЦИИ/ui',
                             '/НР\s*\(/ui',
                             '/СП\s*\(/ui',
                             '/ПЗ\s*=/ui',
                             '/ЭМ\s*=/ui',
                             '/ЗПм\s*=/ui',
                             '/ОТм\s*=/ui',
                             '/МАТ\s*=/ui',
                         ];

                         foreach ($pruningPatterns as $pattern) {
                             if (preg_match($pattern, $val, $matches, PREG_OFFSET_CAPTURE)) {
                                 $val = trim(mb_substr($val, 0, $matches[0][1]));
                             }
                         }
                         
                         // Также если в названии много строк, и после первой идет пустая или системная
                         // Но пока ограничимся паттернами.
                    }
                    
                    $data[$field] = $val;
                }
            }
        }
        
        // ⭐ FALLBACK ДЛЯ ЕДИНИЦЫ ИЗМЕРЕНИЯ: Если unit пустой, пробуем найти его в названии (например, "(100 м3)")
        if (empty($data['unit']) && !empty($data['name'])) {
            // Ищем паттерн в скобках или в конце строки
            // (100 м3), (м3), (шт), (1000 м2)
            if (preg_match('/\((\d*\s*[\p{L}\d\/]+)\)$/u', $data['name'], $matches) || 
                preg_match('/^[\p{L}\d\s\-]+,\s*([\p{L}\d\/]+)$/u', $data['name'], $matches)) {
                
                $unitCandidate = $matches[1];
                // Фильтруем слишком длинные совпадения
                if (mb_strlen($unitCandidate) < 15 && !is_numeric($unitCandidate)) {
                    $data['unit'] = $unitCandidate;
                    Log::debug("[ExcelParser] Unit extracted from name: {$unitCandidate}", ['row' => $rowNum]);
                }
            }
        }
        
        // ⭐ FALLBACK ДЛЯ НАЗВАНИЯ РАЗДЕЛА: Если Name пустое, но в колонке A или B есть текст
        // Часто разделы пишут в A, объединяя ячейки, а mapping настроен на C (Наименование работ)
        if (empty($data['name'])) {
            $fallbackColumns = ['A', 'B'];
            foreach ($fallbackColumns as $col) {
                // Не используем fallback, если эта колонка уже замаплена на что-то другое (кроме section_number)
                // Но section_number часто в A, поэтому проверяем контекст
                if ($col === ($columnMapping['name'] ?? null)) continue;
                
                $cellVal = $sheet->getCell($col . $rowNum)->getValue();
                $strVal = trim((string)$cellVal);
                
                // Если похоже на раздел (начинается с "Раздел", "Глава" или просто длинный текст без цифр в начале)
                if (!empty($strVal) && mb_strlen($strVal) > 5 && !is_numeric($strVal)) {
                     // Дополнительная проверка: это не должно быть значением другого поля (например код)
                     if ($col === ($columnMapping['code'] ?? null)) continue;
                     
                     Log::debug("[ExcelParser] Found potential section name in column {$col}", ['val' => $strVal]);
                     $data['name'] = $strVal;
                     break;
                }
            }
        }
        
        // 🔍 ЛОГИРОВАНИЕ (теперь без ограничения <= 10, чтобы видеть все строки)
        if ($rowNum >= 30 && $rowNum <= 50) {
            Log::info("[ExcelParser] Row {$rowNum} extracted data", [
                'row' => $rowNum,
                'name' => substr($data['name'] ?? '', 0, 50), 
                'style' => $data['style']
            ]);
        }
        
        // Улучшенное извлечение кода норматива
        $data = $this->enrichWithCode($data);
        
        return $data;
    }
    
    /**
     * Извлечь код норматива из данных строки
     * 
     * @param array $data Данные строки
     * @return array Обогащенные данные
     */
    private function enrichWithCode(array $data): array
    {
        $originalName = $data['name'] ?? '';
        $codeFromColumn = $data['code'] ?? '';
        
        // ⭐ ОБРАБОТКА "ЦЕНА ПОСТАВЩИКА"
        if (mb_stripos($codeFromColumn, 'цена поставщика') !== false) {
            $data['code'] = 'PRICE_VENDOR';
            $data['code_type'] = 'vendor_price';
            $data['code_normalized'] = 'PRICE_VENDOR';
            // Если в названии тоже есть мусор про МАТ=..., можно почистить, но обычно это в другой строке
            return $data;
        }
        
        // ⭐ ФИЛЬТР ПСЕВДО-КОДОВ: игнорировать служебные строки
        if (!empty($codeFromColumn) && $this->codeService->isPseudoCode($codeFromColumn)) {
            Log::debug('[ExcelParser] Псевдо-код игнорируется', [
                'code' => $codeFromColumn,
                'name' => substr($originalName, 0, 50),
            ]);
            // Очистить псевдо-код
            $data['code'] = null;
            $codeFromColumn = '';
        }
        
        // Если код уже есть в отдельной колонке - нормализуем его
        if (!empty($codeFromColumn)) {
            $extracted = $this->codeService->extractCode($codeFromColumn);
            
            if ($extracted) {
                $data['code'] = $extracted['code'];
                $data['code_type'] = $extracted['type'];
                $data['code_normalized'] = $this->codeService->normalizeCode($extracted['code']);
                
                return $data;
            }
        }
        
        // Если кода нет - пытаемся извлечь из названия
        if (!empty($originalName)) {
            $extracted = $this->codeService->extractCode($originalName);
            
            if ($extracted) {
                // ⭐ Проверка на псевдо-код
                if ($this->codeService->isPseudoCode($extracted['code'])) {
                    Log::debug('[ExcelParser] Псевдо-код из названия игнорируется', [
                        'code' => $extracted['code'],
                        'name' => substr($originalName, 0, 50),
                    ]);
                    return $data;
                }
                
                $data['code'] = $extracted['code'];
                $data['code_type'] = $extracted['type'];
                $data['code_normalized'] = $this->codeService->normalizeCode($extracted['code']);
                
                // Обновляем название - убираем код
                if (!empty($extracted['clean_text'])) {
                    $data['name'] = $extracted['clean_text'];
                }
                
                // Сохраняем оригинальное название в metadata
                $data['metadata'] = array_merge($data['metadata'] ?? [], [
                    'original_name' => $originalName,
                    'code_extracted_from_name' => true,
                ]);
                
                Log::debug('[ExcelParser] Code extracted from name', [
                    'original_name' => $originalName,
                    'extracted_code' => $data['code'],
                    'clean_name' => $data['name'],
                    'code_type' => $data['code_type'],
                ]);
            }
        }
        
        return $data;
    }
    
    /**
     * Проверить, является ли строка служебной (должна быть пропущена)
     * 
     * Служебные строки:
     * - Заголовки групп: "ОТ(ЗТ)", "ЭМ", "М", "ОТм(ЗТм)"
     * - Пояснения: "Объем=...", "Тех.часть...", "Примечание", "ИТОГО"
     * - Категории (одиночные цифры без дефисов): "1", "2", "4"
     * 
     * НО НЕ валидные коды: "1-100-20", "ГЭСН01-01-012-20"
     * 
     * @param array $rowData Данные строки
     * @return bool true если строку нужно пропустить
     */
    private function shouldSkipRow(array $rowData): bool
    {
        $name = trim($rowData['name'] ?? '');
        $code = trim($rowData['code'] ?? '');
        $quantity = $rowData['quantity'] ?? null;
        $unitPrice = $rowData['unit_price'] ?? null;
        $unit = trim($rowData['unit'] ?? '');
        
        // ============================================
        // ЭТАП 1: Если есть код - анализируем его
        // ============================================
        if (!empty($code)) {
        // Если есть валидный код - НЕ пропускать
            if (!$this->codeService->isPseudoCode($code)) {
            return false;
        }
            // Если код это псевдо-код (ОТ, ЭМ, М) - пропускать
            return true;
        }
        
        // ============================================
        // ЭТАП 2: Нет кода - проверяем наличие данных
        // ============================================
        // Если есть количество ИЛИ цена ИЛИ единица измерения - это ДАННЫЕ, не пропускать
        if ($quantity !== null || $unitPrice !== null || !empty($unit)) {
            return false;
        }
        
        // ============================================
        // ЭТАП 3: Проверка на явные служебные строки
        // ============================================
        $skipPatterns = [
            '/^Объем\s*=/ui',
            '/^Тех\.?\s*часть/ui',
            '/^Примечание/ui',
            '/^ИТОГО\s+по/ui',
            '/^ВСЕГО\s+по/ui',
            '/^В том числе/ui',
            '/^Из них/ui',
            '/^Сумма\s+за/ui',
            '/^(составил|проверил|утвердил|согласовано|принял|сдал)/ui',
            '/^\s*$/u', // Пустые строки
        ];
        
        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        
        // ============================================
        // ЭТАП 4: Анализ названия (только если нет данных)
        // ============================================
        // Если название - псевдо-код (заголовок группы типа ОТ, ЭМ, М)
        if ($this->codeService->isPseudoCode($name)) {
            return true;
        }
        
        // ============================================
        // ИТОГ: Не пропускаем
        // ============================================
        return false;
    }

    /**
     * 🧠 Pre-classify rows using AI in batches
     */
    private function classifyRowsWithAI(Worksheet $sheet, int $startRow, array $columnMapping): void
    {
        $nameColumn = $columnMapping['name'] ?? 'A'; // Default to A if not mapped (fallback)
        if (!$nameColumn) return;

        $maxRow = $sheet->getHighestRow();
        $batchSize = 50;
        $batch = [];
        
        Log::info('[ExcelParser] Starting AI Row Classification', ['total_rows' => $maxRow - $startRow]);

        // Собираем батчи и отправляем
        // TODO: В идеале использовать асинхронные запросы (Guzzle Promises), но пока последовательно для надежности
        
        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = trim((string)$sheet->getCell($nameColumn . $row)->getValue());
            
            if (mb_strlen($val) > 2) { // Пропускаем совсем короткие/пустые
                $batch[$row] = $val;
            }

            if (count($batch) >= $batchSize || $row === $maxRow) {
                if (!empty($batch)) {
                    $results = $this->rowClassifierService->classifyBatch($batch);
                    
                    // Сохраняем результаты в кеш класса
                    foreach ($results as $id => $type) {
                        // Маппим AI типы на наши константы
                        $mappedType = match($type) {
                            'SECTION' => self::ROW_TYPE_SECTION,
                            'ITEM' => self::ROW_TYPE_ITEM,
                            'SUMMARY' => self::ROW_TYPE_SUMMARY,
                            default => self::ROW_TYPE_IGNORE,
                        };
                        $this->aiRowTypes[$id] = $mappedType;
                    }
                    
                    Log::debug('[ExcelParser] Processed AI batch', [
                        'rows' => count($batch), 
                        'results' => count($results)
                    ]);
                    
                    $batch = [];
                }
            }
        }
    }

    /**
     * 🧠 Detect Price Strategy using AI
     */
    private function detectPriceStrategy(Worksheet $sheet, int $headerRow, array $columnMapping): void
    {
        // 1. Находим колонки с ценами
        $priceColumns = [];
        if (!empty($columnMapping['unit_price'])) $priceColumns[] = $columnMapping['unit_price'];
        if (!empty($columnMapping['total_price'])) $priceColumns[] = $columnMapping['total_price'];
        // Также проверим колонки, похожие на цену, но не замапленные (если mapping не идеален)
        
        if (empty($priceColumns)) {
            Log::info('[ExcelParser] No price columns mapped, skipping AI strategy detection');
            return;
        }
        
        // 2. Собираем примеры "сложных" ячеек (где есть перенос строки и числа)
        $samples = [];
        $maxSamples = 5;
        $startRow = $headerRow + 1;
        $maxRow = min($startRow + 50, $sheet->getHighestRow()); // Смотрим первые 50 строк данных
        
        foreach ($priceColumns as $col) {
            for ($row = $startRow; $row <= $maxRow; $row++) {
                $value = $sheet->getCell($col . $row)->getValue();
                
                // Ищем ячейки с переносом строки И числами
                if (is_string($value) && str_contains($value, "\n")) {
                    // Проверяем, что там действительно цифры
                    if (preg_match('/\d+[\.,]\d+.*\n.*\d+/', $value)) {
                        $samples[] = trim($value);
                        if (count($samples) >= $maxSamples) break 2;
                    }
                }
            }
        }
        
        // 3. Если сложных ячеек нет -> стратегия DEFAULT (обычный парсинг)
        if (empty($samples)) {
            Log::info('[ExcelParser] No multiline price cells found, using DEFAULT strategy');
            $this->priceStrategy = PriceStrategyEnum::DEFAULT;
            return;
        }
        
        // 4. Спрашиваем AI
        Log::info('[ExcelParser] Detecting price strategy with AI...', ['samples' => $samples]);
        
        // Собираем заголовки для контекста
        $headers = [];
        foreach ($columnMapping as $field => $col) {
            if ($col) {
                $headers[] = $field . ': ' . ($this->headerCandidates[0]['raw_values'][$col] ?? '');
            }
        }
        
        $this->priceStrategy = $this->priceStrategyService->detectStrategy($samples, $headers);
        
        Log::info('[ExcelParser] Price strategy detected', ['strategy' => $this->priceStrategy]);
    }

    private function parseNumericValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        // Handle newlines based on AI Strategy
        if (is_string($value) && str_contains($value, "\n")) {
            $lines = explode("\n", $value);
            
            // Фильтруем пустые строки
            $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
            
            if (empty($lines)) return null;
            
            // Выбор значения по стратегии
            $rawValue = match ($this->priceStrategy) {
                PriceStrategyEnum::TOP => $lines[0],
                PriceStrategyEnum::BOTTOM => end($lines),
                PriceStrategyEnum::MAX => null, // Обработаем ниже
                default => $lines[0], // Default behavior (top)
            };
            
            // Если стратегия MAX или нужно парсить выбранное значение
            if ($this->priceStrategy === PriceStrategyEnum::MAX) {
                $numbers = [];
                foreach ($lines as $line) {
                    $num = $this->extractFloat($line);
                    if ($num !== null) $numbers[] = $num;
                }
                return !empty($numbers) ? max($numbers) : null;
            }
            
            $value = $rawValue;
        }
        
        return $this->extractFloat($value);
    }
    
    private function extractFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        $str = (string)$value;

        // 1. Если multiline - берем только первую строку (актуально для Сметы, где 1,69 \n 1690/1000)
        if (str_contains($str, "\n")) {
            $lines = explode("\n", $str);
            $str = trim($lines[0]);
        }
        
        // 2. Если содержит слеш / - берем первую часть (1690/1000 -> 1690, но если была первая строка 1.69, то досюда не дойдет)
        if (str_contains($str, '/')) {
            $parts = explode('/', $str);
            $str = trim($parts[0]);
        }
        
        $cleaned = preg_replace('/[^\d.,\-]/', '', $str);
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
        $hasUnit = !empty($rowData['unit']);
        $hasName = !empty($rowData['name']);
        
        // Style check
        $isBold = $rowData['style']['is_bold'] ?? false;
        $isMerged = $rowData['style']['is_merged'] ?? false;
        
        if (!$hasName) {
            return false; // Нет названия - не раздел и не позиция
        }
        
        // ============================================
        // ЖЕСТКИЕ ПРАВИЛА: Это ТОЧНО НЕ РАЗДЕЛ, а позиция
        // ============================================
        
        // 1. Если есть код работы (ГЭСН/ФЕР/ТЕР и т.д.), это ВСЕГДА позиция, НЕ секция!
        $code = $rowData['code'] ?? '';
        if (!empty($code) && !$this->codeService->isPseudoCode($code)) {
            if ($this->codeService->isValidCode($code)) {
                return false; // Это позиция!
            }
        }
        
        // 2. Если есть количество ИЛИ цена - это скорее всего позиция
        if ($hasQuantity || $hasPrice) {
            // Исключение: иногда заголовки разделов имеют суммарную стоимость, но они обычно BOLD и MERGED
            // Если НЕ Bold и есть цена -> точно позиция
            if (!$isBold) {
                return false;
            }
        }
        
        // 3. ⭐ КРИТИЧНО: Если есть единица измерения - это часто позиция
        // НО: Если это BOLD строка без цены/количества, это может быть раздел с мусором в колонке ед.изм
        if ($hasUnit && !$isBold) {
             return false;
        }
        
        // ============================================
        // ПРАВИЛА ДЛЯ РАЗДЕЛОВ
        // ============================================
        
        // 4. Проверяем явные признаки раздела в названии (Раздел, Глава)
        $name = mb_strtolower($rowData['name']);
        $sectionPatterns = [
            '/^раздел\s+\d+/u',
            '/^раздел\s+\d+\./u',
            '/^глава\s+\d+/u',
            '/^этап\s+\d+/u',
            '/^часть\s+\d+/u',
            '/^\d+\.\s+[А-ЯЁ]/u',
        ];
        
        foreach ($sectionPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true; // Это раздел
            }
        }
        
        // 5. Если строка жирная (BOLD) или объединенная (MERGED) и нет явных признаков позиции (цена/кол-во)
        // И это НЕ итоговая строка (Итого, Всего, Накладные, Прибыль, НДС)
        $isSummary = preg_match('/^(итого|всего|накладные|сметная прибыль|сметн\.прибыль|ндс|строительные работы|монтажные работы|оборудование|прочие|зарплата|справочно|начисления)/ui', $name);
        
        if (($isBold || $isMerged) && !$hasQuantity && !$hasPrice && !$isSummary) {
            Log::debug('[ExcelParser] Жирный/Объединенный шрифт и нет данных - ЭТО РАЗДЕЛ', [
                'name' => substr($rowData['name'] ?? '', 0, 100),
            ]);
            return true;
        }
        
        // 6. Если есть иерархический номер (1, 1.1, 1.2) и нет данных
        $sectionNumber = $rowData['section_number'] ?? '';
        $hasHierarchicalNumber = preg_match('/^\d+(\.\d+)*\.?$/', $sectionNumber);
        
        if ($hasHierarchicalNumber && !$hasQuantity && !$hasPrice) {
            return true; // Это раздел
        }
        
        // 7. Название ПОЛНОСТЬЮ заглавными буквами и это не Итоговая строка
        if (mb_strtoupper($rowData['name']) === $rowData['name'] && mb_strlen($rowData['name']) > 3 && !$isSummary) {
            return true; // Это раздел
        }
        
        // 8. ⭐ ДОПОЛНИТЕЛЬНО: Если название содержит "Раздел" или "Глава", даже если есть цена (иногда бывает сумма по разделу в заголовке)
        // Но при этом строка должна быть BOLD
        if ($isBold && (mb_stripos($name, 'раздел') !== false || mb_stripos($name, 'глава') !== false)) {
             return true;
        }

        
        return false;
    }

    /**
     * Автоматическое создание разделов по типам позиций
     */
    private function createDefaultSections(array $items): array
    {
        // Маппинг типов на человекочитаемые названия разделов
        $sectionNames = [
            'work' => 'Работы',
            'material' => 'Материалы',
            'equipment' => 'Механизмы и оборудование',
            'labor' => 'Трудозатраты',
            'other' => 'Прочее',
        ];
        
        // Анализируем какие типы позиций есть в смете
        $typesUsed = [];
        foreach ($items as $item) {
            $type = $item['item_type'] ?? 'work';
            
            // Пропускаем итоговые строки (summary) - они не должны быть в разделах
            if ($type === 'summary') {
                continue;
            }
            
            if (!isset($typesUsed[$type])) {
                $typesUsed[$type] = 0;
            }
            $typesUsed[$type]++;
        }
        
        Log::info('[ExcelParser] Анализ типов позиций', [
            'types_found' => array_keys($typesUsed),
            'counts' => $typesUsed,
        ]);
        
        // Создаем разделы для каждого используемого типа
        $sections = [];
        $sectionNumbers = [];
        $sectionIndex = 1;
        
        // Определяем порядок разделов (сначала работы, потом материалы, и т.д.)
        $typeOrder = ['work', 'material', 'equipment', 'labor', 'other'];
        
        foreach ($typeOrder as $type) {
            if (isset($typesUsed[$type])) {
                $sectionNumber = (string)$sectionIndex;
                $sectionNumbers[$type] = $sectionNumber;
                
                $sections[] = [
                    'row_number' => null, // Автоматически созданный раздел
                    'section_number' => $sectionNumber,
                    'item_name' => $sectionNames[$type] ?? ucfirst($type),
                    'unit' => null,
                    'quantity' => null,
                    'unit_price' => null,
                    'code' => null,
                    'is_section' => true,
                    'item_type' => $type,
                    'level' => 1,
                    'section_path' => null,
                    'raw_data' => [
                        'auto_generated' => true,
                        'items_count' => $typesUsed[$type],
                    ],
                ];
                
                Log::debug('[ExcelParser] Создан автоматический раздел', [
                    'section_number' => $sectionNumber,
                    'name' => $sectionNames[$type] ?? ucfirst($type),
                    'type' => $type,
                    'items_count' => $typesUsed[$type],
                ]);
                
                $sectionIndex++;
            }
        }
        
        // Присваиваем каждой позиции соответствующий раздел
        $updatedItems = [];
        foreach ($items as $item) {
            $type = $item['item_type'] ?? 'work';
            
            // Пропускаем итоговые строки (summary) - они не нужны в импорте
            if ($type === 'summary') {
                Log::debug('[ExcelParser] Итоговая строка пропущена (summary)', [
                    'name' => substr($item['item_name'] ?? '', 0, 100),
                ]);
                continue;
            }
            
            if (isset($sectionNumbers[$type])) {
                $item['section_path'] = $sectionNumbers[$type];
            }
            
            $updatedItems[] = $item;
        }
        
        return [
            'sections' => $sections,
            'items' => $updatedItems,
            'auto_generated_sections' => true, // Флаг для метаданных
        ];
    }

    private function calculateSectionLevel(?string $sectionNumber): int
    {
        // Если номер отсутствует, это может быть корневой раздел (если определен как раздел)
        if (empty($sectionNumber)) {
            return 1;
        }
        
        $normalized = rtrim($sectionNumber, '.');
        
        // Поддержка простых номеров (1, 2, 3) как разделов уровня 1
        if (preg_match('/^\d+$/', $normalized)) {
            return 1;
        }
        
        // Поддержка иерархических номеров (1.1, 1.2.3)
        if (preg_match('/^\d+(\.\d+)+$/', $normalized)) {
             return substr_count($normalized, '.') + 1;
        }
        
        // Fallback
        return 1;
    }

    private function calculateTotals(array $items): array
    {
        $totalAmount = 0;
        $totalQuantity = 0;
        $itemsCount = 0;
        
        foreach ($items as $item) {
            $type = $item['item_type'] ?? 'work';
            
            // ⭐ Игнорируем разделы и итоговые строки при подсчете общей суммы
            // Мы должны считать только сами работы/материалы
            if ($type === 'section' || $type === 'summary') {
                continue;
            }
            
            $quantity = $item['quantity'] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;
            $totalAmount += $quantity * $unitPrice;
            $totalQuantity += $quantity;
            $itemsCount++;
        }
        
        return [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'items_count' => $itemsCount,
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
        
        // ✅ Включаем вычисление формул
        \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableBranchPruning();
        
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
        
        // Проверка на объединенные ячейки (информационно, не критично)
        if ($candidate['has_merged_cells'] ?? false) {
            $issues[] = 'merged_cells_detected';
        }
        
        // Проверка на малое количество колонок
        $filledColumns = $candidate['filled_columns'] ?? 0;
        if ($filledColumns < 3) { // Снизили порог с 5 до 3
            $issues[] = 'few_columns';
        }
        
        // Проверка на многострочность (информационно, не критично)
        if ($candidate['is_multiline'] ?? false) {
            $issues[] = 'multiline_header';
        }
        
        // Проверка позиции УДАЛЕНА - она не нужна, мы используем content-based detection
        
        return $issues;
    }
    
    /**
     * 🤖 Получить примеры строк для AI анализа
     */
    private function getSampleRowsForAI(Worksheet $sheet, int $headerRow, int $count = 5): array
    {
        $samples = [];
        $startRow = $headerRow + 1;
        $maxRow = min($headerRow + 20, $sheet->getHighestRow());
        $highestCol = $sheet->getHighestColumn();
        
        for ($row = $startRow; $row <= $maxRow && count($samples) < $count; $row++) {
            $rowData = [];
            $hasData = false;
            
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
            
            for ($colIdx = 1; $colIdx <= $highestColIndex; $colIdx++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                $cell = $sheet->getCell($col . $row);
                try {
                    $value = $cell->getCalculatedValue();
                } catch (\Exception $e) {
                    $value = $cell->getValue();
                }
                
                if ($value !== null && trim((string)$value) !== '') {
                    $hasData = true;
                }
                $rowData[$col] = $value;
            }
            
            if ($hasData) {
                $samples[] = $rowData;
            }
        }
        
        return $samples;
    }
    
    /**
     * Формирует информацию о распознанных колонках
     */
    private function getDetectedColumnsInfo(array $columnMapping): array
    {
        $detectedColumns = [];
        $reverseMapping = array_flip(array_filter($columnMapping));
        
        foreach ($columnMapping as $field => $columnLetter) {
            if ($columnLetter !== null) {
                $detectedColumns[$columnLetter] = [
                    'field' => $field,
                    'confidence' => 0.9 // TODO: Calculate actual confidence
                ];
            }
        }
        
        return $detectedColumns;
    }
    
    /**
     * 🤖 Объединить AI маппинг с существующим
     */
    private function mergeAIMapping(array $existingMapping, array $aiMapping): array
    {
        $merged = $existingMapping;
        
        foreach ($aiMapping['fields'] as $field => $aiField) {
            $column = $aiField['column'] ?? null;
            $confidence = $aiField['confidence'] ?? 0;
            
            // Если AI уверен (>0.8) и поле еще не замаплено, используем AI результат
            if ($column && $confidence > 0.8) {
                if (empty($merged[$field]) || $confidence > 0.9) {
                    $merged[$field] = $column;
                    Log::debug('[ExcelParser] AI mapped field', [
                        'field' => $field,
                        'column' => $column,
                        'confidence' => $confidence
                    ]);
                }
            }
        }
        
        return $merged;
    }
    
    /**
     * 🤖 Улучшенное определение секции с помощью AI
     */
    private function isSectionRowWithAI(array $rowData, array $context = []): bool
    {
        // Сначала применяем жесткие правила
        $ruleBasedResult = $this->isSectionRow($rowData);
        
        if (!$this->useAI || !$this->aiSectionDetector) {
            return $ruleBasedResult;
        }
        
        // Если правила сказали "ДА" - верим правилам
        if ($ruleBasedResult) {
            return true;
        }
        
        // Если правила сказали "НЕТ", но у нас есть сомнения (например, жирный шрифт), спрашиваем AI
        $isBold = $rowData['style']['is_bold'] ?? false;
        $hasData = ($rowData['quantity'] ?? 0) > 0 || ($rowData['unit_price'] ?? 0) > 0;
        
        // Если это строка с данными (цена/кол-во) и она НЕ жирная - это точно позиция, AI не нужен
        if ($hasData && !$isBold) {
            return false;
        }
        
        // Сомнения возникают если:
        // 1. Нет данных, но есть название (пограничный случай)
        // 2. Есть данные, но строка ЖИРНАЯ (может быть заголовок с суммой)
        // 3. Есть единица измерения, но нет цены (заголовок с мусором)
        
        if ($isBold || !$hasData) {
            $aiResult = $this->aiSectionDetector->detectSection($rowData, $context);
            
            if ($aiResult['confidence'] >= 0.7) {
                 Log::debug('[ExcelParser] AI section override', [
                    'name' => substr($rowData['name'] ?? '', 0, 50),
                    'is_section' => $aiResult['is_section'],
                    'confidence' => $aiResult['confidence']
                ]);
                return $aiResult['is_section'];
            }
        }
        
        return $ruleBasedResult;
    }

    /**
     * =========================================================================
     * 🧠 HEURISTIC ANALYSIS ENGINE (SCORING SYSTEM)
     * =========================================================================
     */

    /**
     * Classify a row based on scoring system AND AI results
     * 
     * @param array $row Cleaned row data
     * @param int $rowNum Row number for AI cache lookup
     * @return string One of ROW_TYPE_* constants
     */
    private function classifyRow(array $row, int $rowNum): string
    {
        // 1. Сначала проверяем AI вердикт (если есть)
        if (isset($this->aiRowTypes[$rowNum])) {
            $aiType = $this->aiRowTypes[$rowNum];
            if ($aiType !== self::ROW_TYPE_IGNORE) {
                return $aiType;
            }
            // Если AI сказал IGNORE, мы можем все равно проверить через Scorer на всякий случай, 
            // или довериться AI. Давайте доверимся AI для IGNORE тоже, но с проверкой данных.
            // Если AI сказал IGNORE, но там есть явная цена и код -> это ошибка AI, берем Scorer.
        }

        $scores = $this->calculateRowScores($row);
        
        // ... (rest of the logic)
        $winner = self::ROW_TYPE_IGNORE;
        $maxScore = 0;
        
        foreach ($scores as $type => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $winner = $type;
            }
        }
        
        // Threshold check (must be at least 20 points to be anything)
        if ($maxScore < 20) {
            return self::ROW_TYPE_IGNORE;
        }
        
        // Log challenging classifications
        if ($maxScore < 100) {
            Log::debug('[ExcelParser] Low confidence classification', [
                'name' => substr($row['name'] ?? '', 0, 50),
                'winner' => $winner,
                'scores' => $scores
            ]);
        }
        
        // Special check: If Section wins but has data -> might be Summary if not explicitly Section
        if ($winner === self::ROW_TYPE_SECTION) {
            $hasData = ($row['quantity'] ?? 0) > 0 || ($row['unit_price'] ?? 0) > 0;
            if ($hasData && $scores[self::ROW_TYPE_SUMMARY] > 0) {
                // If it has data and some summary signs, prefer summary over section to be safe
                // But only if section score isn't overwhelming (>200)
                if ($scores[self::ROW_TYPE_SECTION] < 200) {
                    return self::ROW_TYPE_SUMMARY;
                }
            }
        }
        
        return $winner;
    }

    /**
     * Calculate probability scores for each row type
     * 
     * @param array $row
     * @return array ['item' => int, 'section' => int, 'summary' => int]
     */
    private function calculateRowScores(array $row): array
    {
        $scores = [
            self::ROW_TYPE_ITEM => 0,
            self::ROW_TYPE_SECTION => 0,
            self::ROW_TYPE_SUMMARY => 0,
        ];
        
        $name = trim($row['name'] ?? '');
        $code = trim($row['code'] ?? '');
        $unit = trim($row['unit'] ?? '');
        
        $hasQuantity = ($row['quantity'] ?? 0) > 0;
        $hasPrice = ($row['unit_price'] ?? 0) > 0;
        $isBold = $row['style']['is_bold'] ?? false;
        
        // ---------------------------------------------------------
        // 0. PRE-CHECKS (NUCLEAR OPTION)
        // ---------------------------------------------------------
        
        // Detect "Column Numbers" row (e.g. name="3", qty="4", price="5")
        // If name is a small number -> almost certainly IGNORE (but here we treat as low scores)
        if (preg_match('/^\d+$/', $name) && (int)$name < 20) {
             // This will result in IGNORE because all scores stay 0 or become negative
             return $scores; 
        }

        // ---------------------------------------------------------
        // 1. ITEM SCORING
        // ---------------------------------------------------------
        
        // TRUMP CARD: Valid Code (FER/GESN)
        if (!empty($code) && !$this->codeService->isPseudoCode($code)) {
            if ($this->codeService->isValidCode($code)) {
                $scores[self::ROW_TYPE_ITEM] += 500;
            } else {
                // Code exists but maybe custom
                $scores[self::ROW_TYPE_ITEM] += 100;
            }
        }
        
        // Has Data
        if ($hasPrice && $hasQuantity) $scores[self::ROW_TYPE_ITEM] += 100;
        elseif ($hasPrice || $hasQuantity) $scores[self::ROW_TYPE_ITEM] += 50;
        
        // Has Unit
        if (!empty($unit)) $scores[self::ROW_TYPE_ITEM] += 50;
        
        // Penalties for Item
        if ($isBold) $scores[self::ROW_TYPE_ITEM] -= 20; // Items are rarely bold
        
        // 🛡️ SECURITY: If price is huge (> 1M) and no code -> likely a SUMMARY line being misidentified
        if (($row['unit_price'] ?? 0) > 1000000 && empty($code)) {
            $scores[self::ROW_TYPE_ITEM] -= 200;
            $scores[self::ROW_TYPE_SUMMARY] += 200;
        }
        
        // ---------------------------------------------------------
        // 2. SECTION SCORING
        // ---------------------------------------------------------
        
        // Keywords
        if (preg_match('/^(раздел|глава|этап|часть|локальная смет)/ui', $name)) {
            $scores[self::ROW_TYPE_SECTION] += 200;
        }
        
        // Hierarchical numbering (1., 1.2., II.)
        if (preg_match('/^(\d+\.|[IVX]+\.)\s+/u', $name)) {
            $scores[self::ROW_TYPE_SECTION] += 50;
        }
        
        // Styling
        if ($isBold) $scores[self::ROW_TYPE_SECTION] += 50;
        if ($row['style']['is_merged'] ?? false) $scores[self::ROW_TYPE_SECTION] += 30;
        
        // Caps lock (at least 5 chars)
        if (mb_strlen($name) > 5 && mb_strtoupper($name) === $name) {
            $scores[self::ROW_TYPE_SECTION] += 30;
        }
        
        // Absence of data (Sections usually don't have prices in columns, or they have total sum)
        if (!$hasPrice && !$hasQuantity && empty($unit)) {
            $scores[self::ROW_TYPE_SECTION] += 50;
        }
        
        // Penalties for Section
        // If it has code, it's very unlikely to be a section
        if (!empty($code) && !$this->codeService->isPseudoCode($code)) {
            $scores[self::ROW_TYPE_SECTION] -= 100;
        }
        
        // ---------------------------------------------------------
        // 3. SUMMARY SCORING
        // ---------------------------------------------------------
        
        // Strong Keywords
        if (preg_match('/^(итого|всего|накладные|сметная прибыль|ндс|строительные работы|монтажные работы|оборудование|прочие|зарплата|справочно|в базисном|в текущем)/ui', $name)) {
            $scores[self::ROW_TYPE_SUMMARY] += 300;
        }
        
        // Secondary Keywords
        if (preg_match('/(в т\.ч\.|в том числе|начисления|коэффициент|индекс)/ui', $name)) {
            $scores[self::ROW_TYPE_SUMMARY] += 150;
        }
        
        // Styling
        if ($isBold) $scores[self::ROW_TYPE_SUMMARY] += 20;
        
        // Summaries often have price but no unit and no code
        if ($hasPrice && empty($unit) && empty($code)) {
            $scores[self::ROW_TYPE_SUMMARY] += 50;
        }
        
        // Penalties for Summary
        if (!empty($code) && !$this->codeService->isPseudoCode($code)) {
            $scores[self::ROW_TYPE_SUMMARY] -= 100;
        }
        
        return $scores;
    }
}


