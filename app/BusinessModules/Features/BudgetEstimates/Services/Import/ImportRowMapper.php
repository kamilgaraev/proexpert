<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class ImportRowMapper
{
    private array $sectionHints = [];
    private array $rowStyles = [];

    private const COMMON_UNITS = [
        'шт', 'м', 'кг', 'т', 'м3', 'м2', 'км', 'чел.-ч', 'маш.-час', 'компл', 'компл.', 'ед', 'пог. м', 'пог.м', '100 м', '1000 м3', '100 м2', '100 м3', 'тн', 'усл. ед', 'уп'
    ];

    public function setSectionHints(array $hints): void
    {
        $this->sectionHints = $hints;
    }

    public function setRowStyles(array $styles): void
    {
        $this->rowStyles = $styles;
    }

    /**
     * Map raw row data to EstimateImportRowDTO based on column mapping.
     */
    public function map(EstimateImportRowDTO $rowDTO, array $mapping): EstimateImportRowDTO
    {
        $rawData = $rowDTO->rawData;
        if (empty($rawData) || empty($mapping)) {
            return $rowDTO;
        }

        // 1. Handle duplicate column mapping (Name and Unit pointing to same column)
        $nameCol = $mapping['name'] ?? $mapping['item_name'] ?? null;
        $unitCol = $mapping['unit'] ?? null;
        if ($nameCol !== null && $nameCol === $unitCol) {
            unset($mapping['unit']);
        }

        // 1. Check if it's a "technical" row (e.g., 1, 2, 3, 4, 5... guide row)
        if ($this->isTechnicalRow($rawData)) {
            // Signal to skip this row by returning a special DTO or marking it
            // For now, let's mark it as something we can ignore or just return as is but empty?
            // Actually, the caller (Pipeline/Service) should handle the skip.
            // Let's add an 'ignore' flag if we had it. Since we don't, we'll mark as section with empty name?
            // Better: add a check in the loop in Service/Pipeline.
        }

        $mappedData = [
            'rowNumber' => $rowDTO->rowNumber,
            'itemName' => '',
            'unit' => null,
            'quantity' => null,
            'unitPrice' => null,
            'currentTotalAmount' => null,
            'code' => null,
            'sectionNumber' => null,
            'quantityCoefficient' => null,
            'quantityTotal' => null,
            'baseUnitPrice' => null,
            'baseLaborCost' => 0.0,
            'baseMachineryCost' => 0.0,
            'baseMachineryLaborCost' => 0.0,
            'baseMaterialsCost' => 0.0,
            'priceIndex' => null,
            'currentUnitPrice' => null,
            'priceCoefficient' => null,
            'itemType' => 'work',
            'level' => 0,
            'sectionPath' => null,
            'rawData' => $rawData,
            'isFooter' => false,
            'isSection' => false,
        ];

        // 0. Capture Raw Name for attribute parsing (before cleaning)
        $rawNameForParsing = '';

        // 1. Fill from Mapping
        foreach ($mapping as $field => $column) {
            if ($column === null && $field !== 'name' && $field !== 'item_name') continue;
            
            $value = $this->getValueFromRaw($rawData, $column);
            
            // Если ячейка пустая, не обрабатываем её, чтобы не затереть данные из других колонок
            if ($value === null || trim((string)$value) === '') continue;
            
            switch ($field) {
                case 'name':
                case 'item_name':
                    $rawNameForParsing = (string)$value;
                    $mappedData['itemName'] = $this->cleanName($rawNameForParsing);
                    break;
                case 'unit':
                    $mappedData['unit'] = trim((string)$value) ?: null;
                    break;
                case 'quantity':
                case 'amount':
                    $mappedData['quantity'] = $this->parseFloat($value);
                    break;
                case 'unit_price':
                case 'price':
                    $parsed = $this->parseMultiLineValue($value);
                    $mappedData['unitPrice'] = $parsed['total'];
                    // unit_price в контексте ФЕР - это ТЕКУЩАЯ цена.
                    if ($mappedData['currentUnitPrice'] === null) {
                        $mappedData['currentUnitPrice'] = $parsed['total'];
                    }
                    
                    // БЕРЕМ БАЗИСНЫЕ КОМПОНЕНТЫ ИЗ ТЕКУЩЕЙ КОЛОНКИ ТОЛЬКО ЕСЛИ НЕТ СПЕЦИАЛЬНОЙ БАЗОВОЙ КОЛОНКИ
                    $hasSeparateBaseColumn = isset($mapping['base_unit_price']);
                    if (!$hasSeparateBaseColumn) {
                        if ($mappedData['baseLaborCost'] == 0) $mappedData['baseLaborCost'] = $parsed['labor'];
                        if ($mappedData['baseMachineryCost'] == 0) $mappedData['baseMachineryCost'] = $parsed['machinery'];
                        if ($mappedData['baseMachineryLaborCost'] == 0) $mappedData['baseMachineryLaborCost'] = $parsed['machinery_labor'];
                        if ($mappedData['baseMaterialsCost'] == 0) $mappedData['baseMaterialsCost'] = $parsed['materials'];
                    }
                    break;
                case 'current_total_amount':
                case 'total_amount':
                    $parsed = $this->parseMultiLineValue($value);
                    $mappedData['currentTotalAmount'] = $parsed['total'];
                    break;
                case 'base_unit_price':
                    $parsed = $this->parseMultiLineValue($value);
                    $mappedData['baseUnitPrice'] = $parsed['total'];
                    // Заполняем компоненты ТОЛЬКО если они реально найдены в этой ячейке
                    if ($parsed['labor'] > 0) $mappedData['baseLaborCost'] = $parsed['labor'];
                    if ($parsed['machinery'] > 0) $mappedData['baseMachineryCost'] = $parsed['machinery'];
                    if ($parsed['machinery_labor'] > 0) $mappedData['baseMachineryLaborCost'] = $parsed['machinery_labor'];
                    if ($parsed['materials'] > 0) $mappedData['baseMaterialsCost'] = $parsed['materials'];
                    break;
                case 'base_labor_price':
                case 'labor_price':
                    $parsed = $this->parseMultiLineValue($value);
                    $mappedData['baseLaborCost'] = $parsed['total'];
                    break;
                case 'base_machinery_price':
                case 'machinery_price':
                    $parsed = $this->parseMultiLineValue($value);
                    $mappedData['baseMachineryCost'] = $parsed['total'];
                    if ($parsed['labor'] > 0) {
                        $mappedData['baseMachineryLaborCost'] = $parsed['labor'];
                    }
                    break;
                case 'base_materials_price':
                case 'materials_price':
                    $mappedData['baseMaterialsCost'] = $this->parseFloat($value);
                    break;
                case 'quantity_total':
                    $mappedData['quantityTotal'] = $this->parseFloat($value);
                    break;
                case 'price_index':
                    $mappedData['priceIndex'] = $this->parseFloat($value);
                    break;
                case 'current_unit_price':
                    $mappedData['currentUnitPrice'] = $this->parseFloat($value);
                    break;
                case 'base_machinery_labor_price':
                case 'machinery_labor_price':
                    $mappedData['baseMachineryLaborCost'] = $this->parseFloat($value);
                    break;
                case 'price_coefficient':
                    $mappedData['priceCoefficient'] = $this->parseFloat($value);
                    break;
                default:
                    if (array_key_exists($field, $mappedData)) {
                        $mappedData[$field] = $this->parseFloat($value);
                    }
                    break;
            }
        }

        // 2. Item Name Fallback & Unit Splitting
        // If name is empty, try to find it in the row (context for sections)
        if (empty($mappedData['itemName'])) {
            foreach ($rawData as $val) {
                $v = trim((string)$val);
                // Skip numeric values
                if (is_numeric($v)) continue;
                
                // Skip short strings
                if (mb_strlen($v) <= 5) continue;
                
                // Skip Codes (e.g. "ФЕРм08-02-142-01" or "1.1-1-11")
                // Heuristic: Codes usually don't have spaces or Cyrillic words (except prefix)
                // If it looks like a code (mostly latin/digits/dashes/dots), skip it
                // But be careful: "Кабель ВВГнг..." has latin too.
                // Better heuristic: Codes usually don't have spaces. Names usually do.
                if (strpos($v, ' ') === false) {
                    // It's a single word/token. Likely a code.
                    continue;
                }

                $rawNameForParsing = $v;
                $mappedData['itemName'] = $v; // Usually fallback finds clean names, but we can clean it if needed
                break;
            }
        }

        // Smart Unit Splitting
        $nameCol = $mapping['name'] ?? $mapping['item_name'] ?? null;
        $unitCol = $mapping['unit'] ?? null;
        if (!empty($mappedData['itemName']) && (empty($mappedData['unit']) || $nameCol === $unitCol)) {
            $split = $this->splitNameAndUnit($mappedData['itemName']);
            $mappedData['itemName'] = $split['name'];
            // 6a. If we found a unit in the name (e.g. "100 m3"), but the mapped unit is generic "ед" or "шт", or empty, 
            // prefer the extracted unit.
            // Also cleanup generic "ед" if it comes from mapping but looks suspicious.
            $mappedUnit = $mappedData['unit'] ?? null;
            $extractedUnit = $split['unit'];

            if (!empty($extractedUnit)) {
                // If mapped unit is empty or generic, use extracted
                if (empty($mappedUnit) || in_array(mb_strtolower($mappedUnit), ['ед', 'ед.', 'шт', 'шт.'])) {
                    $mappedData['unit'] = $extractedUnit;
                }
            } elseif (!empty($mappedUnit) && in_array(mb_strtolower($mappedUnit), ['ед', 'ед.'])) {
                // If mapped unit is "ед" and we didn't extract anything better, 
                // check if it's really a unit or just garbage. 
                // For now, let's nullify "ед" if it's the ONLY thing we have and it looks like a default.
                // But be careful, some items might actually be "units". 
                // Let's NOT nullify it blindly, but the above logic handles the override.
            }
        }

        if (!empty($mappedData['itemName']) && !$mappedData['isSection'] && !$mappedData['isFooter']) {
             // Debug log to check what name we picked
             // \Illuminate\Support\Facades\Log::debug("[ImportRowMapper] Item Name detected: '{$mappedData['itemName']}'");
        }

        // 2.5. Smart Parsing of Indices and Rates from Item Name
        // Use rawNameForParsing because itemName is already cleaned from 'Trash' keywords like 'INDEX...'
        $textToParse = !empty($rawNameForParsing) ? $rawNameForParsing : ($mappedData['itemName'] ?? '');

        if (!empty($textToParse) && !$mappedData['isSection'] && !$mappedData['isFooter']) {
            $attributes = $this->parseItemAttributes($textToParse);
            
            if ($attributes['price_index'] > 0) {
                $mappedData['priceIndex'] = $attributes['price_index'];

                if (!empty($mappedData['unitPrice'])) {
                    $mappedData['baseUnitPrice'] = $mappedData['unitPrice'];
                    $mappedData['unitPrice'] = round($mappedData['baseUnitPrice'] * $mappedData['priceIndex'], 2);

                    if (!empty($mappedData['quantity'])) {
                        $mappedData['currentTotalAmount'] = round($mappedData['unitPrice'] * $mappedData['quantity'], 2);
                    }

                    Log::info("[ImportRowMapper] Smart Parsing applied: BasePrice={$mappedData['baseUnitPrice']} * Index={$mappedData['priceIndex']} = NewPrice={$mappedData['unitPrice']}");
                }
            }

            if ($attributes['overhead_rate'] !== null) {
                $mappedData['overheadRate'] = $attributes['overhead_rate'];
            }
            if ($attributes['profit_rate'] !== null) {
                $mappedData['profitRate'] = $attributes['profit_rate'];
            }

            if ($attributes['overhead_rate'] || $attributes['profit_rate']) {
                Log::info("[ImportRowMapper] Rates detected and stored: NR={$attributes['overhead_rate']}%, SP={$attributes['profit_rate']}%");
            }
        }

        $this->applyInversePricing($mappedData);

        // 3. Robust Section & Footer Detection
        $mappedData['isFooter'] = $this->isFooter(
            $mappedData['itemName'], 
            $rawData,
            $mappedData['quantity'],
            $mappedData['unitPrice'],
            $mappedData['unit'],
            $mappedData['currentTotalAmount']
        );

        if (!$mappedData['isFooter']) {
            $mappedData['isSection'] = $this->isSection(
                $mappedData['itemName'], 
                $rawData,
                $mappedData['quantity'],
                $mappedData['unitPrice'],
                $mappedData['unit'],
                $mappedData['currentTotalAmount'],
                $mappedData['rowNumber']
            );
        }

        if ($mappedData['isSection']) {
            // Force clear numeric fields for sections
            $mappedData['quantity'] = null;
            $mappedData['unitPrice'] = null;
            $mappedData['currentTotalAmount'] = null;

            // Fallback for itemName if mapped one is too short or empty
            if (empty($mappedData['itemName']) || mb_strlen($mappedData['itemName']) < 4) {
                foreach ($rawData as $val) {
                    $v = trim((string)$val);
                    if (mb_strlen($v) > 5 && !is_numeric($v)) {
                         $mappedData['itemName'] = $v;
                         break;
                    }
                }
            }
        }

        // Финальный расчет материалов для ФЕР: Мат = ПЗ - ОЗП - ЭМ
        // Делаем это только если материалы явно не были заданы или они равны ПЗ (что бывает при авторасчете из single-line)
        if ($mappedData['baseUnitPrice'] > 0) {
            $calcMaterials = round($mappedData['baseUnitPrice'] - $mappedData['baseLaborCost'] - $mappedData['baseMachineryCost'], 2);
            if ($calcMaterials > 0 && ($mappedData['baseMaterialsCost'] <= 0 || abs($mappedData['baseMaterialsCost'] - $mappedData['baseUnitPrice']) < 0.01)) {
                $mappedData['baseMaterialsCost'] = $calcMaterials;
            }
        }

        $attributes = $this->parseItemAttributes($rawNameForParsing);

        return new EstimateImportRowDTO(
            rowNumber: $mappedData['rowNumber'],
            sectionNumber: $mappedData['sectionNumber'] ?? null,
            itemName: $this->truncate($mappedData['itemName'] ?? '', 255) ?? '',
            unit: $this->truncate($mappedData['unit'] ?? null, 100),
            quantity: $mappedData['quantity'] ?? null,
            unitPrice: $mappedData['unitPrice'] ?? null,
            code: $this->truncate($mappedData['code'] ?? null, 100),
            isSection: $mappedData['isSection'] ?? false,
            itemType: $mappedData['itemType'] ?? 'work',
            level: $mappedData['level'] ?? 0,
            sectionPath: $mappedData['sectionPath'] ?? null,
            rawData: $rawData,
            quantityCoefficient: $mappedData['quantityCoefficient'] ?? null,
            quantityTotal: $mappedData['quantityTotal'] ?? null,
            baseUnitPrice: $mappedData['baseUnitPrice'] ?? 0,
            priceIndex: $mappedData['priceIndex'] ?? null,
            currentUnitPrice: $mappedData['currentUnitPrice'] ?? $mappedData['unitPrice'] ?? 0,
            priceCoefficient: $mappedData['priceCoefficient'] ?? null,
            currentTotalAmount: $mappedData['currentTotalAmount'] ?? null,
            overheadAmount: round($attributes['overhead_amount'] ?? 0, 2),
            profitAmount: round($attributes['profit_amount'] ?? 0, 2),
            overheadRate: $mappedData['overheadRate'] ?? null,
            profitRate: $mappedData['profitRate'] ?? null,
            baseLaborCost: round($mappedData['baseLaborCost'] ?? 0, 2),
            baseMachineryCost: round($mappedData['baseMachineryCost'] ?? 0, 2),
            baseMachineryLaborCost: round($mappedData['baseMachineryLaborCost'] ?? 0, 2),
            baseMaterialsCost: round($mappedData['baseMaterialsCost'] ?? 0, 2),
            isFooter: $mappedData['isFooter'] ?? false
        );
    }

    /**
     * Parse multi-line cell value (common in FER).
     * Line 1: Total
     * Line 2: Labor (ЗП)
     * Line 3: Machinery (ЭМ)
     * Line 4: Labor of Machinery (ЗПМ)
     */
    public function parseMultiLineValue($value): array
    {
        $result = [
            'total' => 0.0,
            'labor' => 0.0,
            'machinery' => 0.0,
            'machinery_labor' => 0.0,
            'materials' => 0.0,
        ];

        if ($value === null || $value === '') {
            return $result;
        }

        $str = (string)$value;
        // Сплитим по всем видам переносов строк, табам или двойным пробелам
        $lines = preg_split('/[\n\r\t]+|\s{2,}/u', $str);
        $lines = array_values(array_filter(array_map('trim', $lines)));
        
        $result['total'] = $this->parseFloat($lines[0] ?? null) ?? 0.0;
        
        // В ФЕР (Гранд-Смета и др.) часто такой порядок в многострочной ячейке:
        // 1. Всего (Прямые затраты)
        // 2. ОЗП (Заработная плата рабочих)
        // 3. ЭМ (Эксплуатация машин)
        // 4. ЗПМ (Заработная плата машинистов) - входит в ЭМ
        // 5. [Иногда] Материалы - если не 5-й, то вычисляется: Всего - ОЗП - ЭМ
        
        $result['labor'] = $this->parseFloat($lines[1] ?? null) ?? 0.0;
        $result['machinery'] = $this->parseFloat($lines[2] ?? null) ?? 0.0;
        $result['machinery_labor'] = $this->parseFloat($lines[3] ?? null) ?? 0.0;
        
        // ЗАЩИТА: В ФЕР компоненты (ЗП, ЭМ) не могут быть больше Прямых Затрат (ПЗ).
        // Если ЗП > ПЗ, значит это не базисные компоненты, а утечка текущих цен.
        if ($result['labor'] > $result['total'] * 1.1 || $result['machinery'] > $result['total'] * 1.1) {
            $result['labor'] = 0.0;
            $result['machinery'] = 0.0;
            $result['machinery_labor'] = 0.0;
        }

        $matCandidate = $this->parseFloat($lines[4] ?? null);
        if ($matCandidate !== null && $matCandidate > 0) {
            $result['materials'] = $matCandidate;
        }

        return $result;
    }

    private function parseItemAttributes(string $text): array
    {
        $attributes = [
            'price_index' => null,
            'overhead_rate' => null,
            'profit_rate' => null,
            'overhead_amount' => null, // Base amount per unit
            'profit_amount' => null,   // Base amount per unit
        ];

        // 1. Parse Price Index (СМР / Индекс)
        if (preg_match('/(?:индекс|смр|к\s*=|к\s*pos)\D{0,20}?(\d+[.,]?\d*)/ui', $text, $matches)) {
            $attributes['price_index'] = (float)str_replace(',', '.', $matches[1]);
        }

        // 2. Parse Overhead (НР)
        // STRICTER REGEX: Use boundaries to avoid matching inside other words
        // Match "НР" or "Накладные" only if not surrounded by letters.
        // Amount: "НР ... ( 1 234,45 руб )" -> support spaces in numbers
        if (preg_match('/(?:^|[^а-яёa-z])(?:нр|накладные)(?![а-яёa-z]).*?\(\s*([\d\s]+[.,]?\d*)\s*(?:руб|р)/ui', $text, $matches)) {
             $attributes['overhead_amount'] = (float)str_replace([' ', ','], ['', '.'], $matches[1]);
        }
        
        // Rate: "НР ... 130%"
        if (preg_match('/(?:^|[^а-яёa-z])(?:нр|накладные)(?![а-яёa-z]).*?(\d+[.,]?\d*)\s*%/ui', $text, $matches)) {
             $attributes['overhead_rate'] = (float)str_replace(',', '.', $matches[1]);
        }

        // 3. Parse Profit (СП)
        // Fix for "справочно" matching "сп": Ensure SP is a whole word.
        // Amount: "СП ... ( 1 234,45 руб )"
        if (preg_match('/(?:^|[^а-яёa-z])(?:сп|сметная)(?![а-яёa-z]).*?\(\s*([\d\s]+[.,]?\d*)\s*(?:руб|р)/ui', $text, $matches)) {
             $attributes['profit_amount'] = (float)str_replace([' ', ','], ['', '.'], $matches[1]);
        }
        
        // Rate: "СП ... 89%"
        if (preg_match('/(?:^|[^а-яёa-z])(?:сп|сметная)(?![а-яёa-z]).*?(\d+[.,]?\d*)\s*%/ui', $text, $matches)) {
             $attributes['profit_rate'] = (float)str_replace(',', '.', $matches[1]);
        }

        return $attributes;
    }

    private function cleanName(string $name): string
    {
        if (empty($name)) return '';
        
        $lines = explode("\n", $name);
        $cleanLines = [];
        
        $trashKeywords = [
            'ИНДЕКС К ПОЗИЦИИ', 
            'НР (', 
            'СП (', 
            'ПЗ=', 
            'ЭМ=', 
            'ЗП=', 
            'МАТ=',
            'ФОТ=',
            'Приказ Минстроя',
            'Перевод цен',
            'Коэффициент',
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;

            $isTrash = false;
            foreach ($trashKeywords as $kw) {
                if (mb_stripos($trimmed, $kw) !== false) {
                    // Use more strict regex to avoid stripping real names containing these words accidentally
                    if (preg_match('/(НР|СП)\s*\(|ИНДЕКС\s+К\s+ПОЗИЦИИ|ФОТ\s*=|ЗП\s*=|МАТ\s*=|^Приказ\s|Перевод\s+цен|Коэффициент(?:\/|\s*=)/ui', $trimmed)) {
                        $isTrash = true;
                        break;
                    }
                }
            }

            if (!$isTrash) {
                $cleanLines[] = $trimmed;
            }
        }

        return trim(implode("\n", $cleanLines));
    }

    public function isTechnicalRow(array $row): bool
    {
        $nonEmpty = array_filter($row, fn($v) => $v !== null && $v !== '');
        if (count($nonEmpty) < 3) return false;

        $numericCount = 0;
        $sequentialCount = 0;
        $prevVal = null;
        $stringValues = [];

        foreach ($nonEmpty as $val) {
            $valStr = trim((string)$val);
            if (empty($valStr)) continue;

            if (is_numeric($valStr)) {
                $numericCount++;
                $current = (float)$valStr;
                if ($prevVal !== null && $current === $prevVal + 1) {
                    $sequentialCount++;
                }
                $prevVal = $current;
            } else {
                // Technical row usually doesn't have many non-numeric strings
                // If we hit a substantial string, it's likely a real data row
                $stringValues[] = $valStr;
                if (mb_strlen($valStr) > 10) return false;
            }
        }

        // If high percentage of row is numeric and we found sequential numbers like 1, 2, 3...
        $isTechnical = ($sequentialCount >= 2 && $numericCount / count($nonEmpty) > 0.7);
        if ($isTechnical) {
             \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Technical (sequential numbers). Content: " . json_encode($nonEmpty, JSON_UNESCAPED_UNICODE));
        }
        return $isTechnical;
    }

    private function isSection(?string $itemName, array $rawData, ?float $quantity = null, ?float $unitPrice = null, ?string $unit = null, ?float $totalAmount = null, int $rowNumber = 0): bool
    {
        if (($quantity !== null && $quantity > 0) || 
            ($unitPrice !== null && $unitPrice > 0) || 
            ($totalAmount !== null && $totalAmount > 0) ||
            !empty($unit)) {
            return false;
        }

        if ($this->isFooter($itemName, $rawData, $quantity, $unitPrice, $unit, $totalAmount)) {
            return false;
        }

        if ($rowNumber > 0 && isset($this->rowStyles[$rowNumber])) {
            $style = $this->rowStyles[$rowNumber];
            if ($style['is_bold_dominant'] || $style['has_background']) {
                Log::info("[ImportRowMapper] Row #{$rowNumber} detected as Section by visual style (bold={$style['is_bold_dominant']}, bg={$style['has_background']})");
                return true;
            }
        }

        $aiKeywords = $this->sectionHints['section_keywords'] ?? [];
        if (!empty($aiKeywords)) {
            $text = mb_strtolower($itemName ?? '');
            foreach ($aiKeywords as $kw) {
                if (mb_stripos($text, mb_strtolower($kw)) !== false) return true;
            }
        }

        // 3. Fallback to standard heuristics
        // Check for "Раздел", "Этап" etc at the start of ANY column in the first 8
        foreach (array_slice($rawData, 0, 8) as $val) {
            $v = trim((string)$val);
            if (empty($v)) continue;
            
            // RESTORED: This was missing, causing "Missing Section 1"
            if (preg_match('/^(раздел|этап|глава|площадка|объект)\s*[0-9]*/ui', $v)) {
                \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Section by pattern in column value: '{$v}'");
                return true; 
            }
        }

        $text = trim($itemName ?? '');
        
        // RESTORED: Logic for item name check
        if (preg_match('/^(раздел|этап|глава|площадка|объект)\s*[0-9]*/ui', $text)) {
             \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Section by pattern in itemName: '{$text}'");
             return true;
        }

        // 4. Pattern "1.1.2. Section Name"
        if (preg_match('/^[0-9]+(\.[0-9]+)+\s+[А-ЯA-Z]/u', $text)) {
            \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Section by numbering pattern: '{$text}'");
            return true;
        }

        return false;
    }

    private function getValueFromRaw(array $rawData, mixed $column): mixed
    {
        if (is_string($column) && isset($rawData[$column])) {
            return $rawData[$column];
        }

        $index = $this->columnIndex($column);
        return $rawData[$index] ?? null;
    }

    private function columnIndex(mixed $column): int
    {
        if (is_numeric($column)) {
            return (int)$column;
        }

        if (is_string($column)) {
            $column = strtoupper($column);
            $length = strlen($column);
            $index = 0;
            for ($i = 0; $i < $length; $i++) {
                $index = $index * 26 + ord($column[$i]) - ord('A') + 1;
            }
            return $index - 1;
        }

        return 0;
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        $str = (string)$value;
        $lines = explode("\n", $str);
        $firstLine = trim($lines[0]);

        $clean = str_replace([' ', "\xc2\xa0", "\xA0"], '', $firstLine);
        $clean = str_replace(',', '.', $clean);

        if (preg_match('/^-?[0-9]*\.?[0-9]+/', $clean, $matches)) {
            $numericChars = preg_replace('/[^0-9\.]/', '', $clean);
            if (mb_strlen($numericChars) / mb_strlen($clean) > 0.5) {
                return (float)$matches[0];
            }
        }

        return null;
    }

    private function cleanCode(mixed $value): ?string
    {
        if (empty($value)) return null;
        $str = (string)$value;
        $lines = explode("\n", $str);
        return trim($lines[0]);
    }

    private function splitNameAndUnit(string $name): array
    {
        $lines = explode("\n", $name);
        $bestUnit = null;
        $unitLineIndex = -1;

        foreach ($lines as $idx => $line) {
            if (preg_match_all('/[\(\[]([^\\(\\)\\[\\]]{1,30})[\)\]]/u', $line, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $candidate = trim($candidate);
                    if (in_array(mb_strtolower($candidate), self::COMMON_UNITS)) {
                        $bestUnit = $candidate;
                        $unitLineIndex = $idx;
                        break 2;
                    }
                    foreach (self::COMMON_UNITS as $common) {
                        if (mb_stripos($candidate, $common) !== false) {
                            $bestUnit = $candidate;
                            $unitLineIndex = $idx;
                        }
                    }
                    if (!$bestUnit && mb_strlen($candidate) < 10 && preg_match('/[а-яёa-z]/ui', $candidate)) {
                        $bestUnit = $candidate;
                        $unitLineIndex = $idx;
                    }
                }
            }
        }

        if ($bestUnit) {
            if ($unitLineIndex !== -1 && trim(preg_replace('/[\(\[]' . preg_quote($bestUnit, '/') . '[\)\]]/u', '', $lines[$unitLineIndex])) === '') {
                 unset($lines[$unitLineIndex]);
            }
            return [
                'name' => trim(implode("\n", $lines)),
                'unit' => $bestUnit
            ];
        }

        $firstLine = trim($lines[0]);
        if (preg_match('/,\s*([а-яёa-z0-9\/ ]{1,15})$/ui', $firstLine, $matches)) {
            $unitFound = trim($matches[1]);
            if (!empty($unitFound) && !preg_match('/[0-9]{5,}/', $unitFound)) {
                return [
                    'name' => $name,
                    'unit' => $unitFound
                ];
            }
        }

        return ['name' => $name, 'unit' => null];
    }

    private function isFooter(?string $itemName, array $rawData, ?float $quantity = null, ?float $unitPrice = null, ?string $unit = null, ?float $totalAmount = null): bool
    {
        // 0. Safety: real items MUST have quantity > 0 OR unit price > 0. 
        if (($quantity !== null && $quantity > 0) || 
            ($unitPrice !== null && $unitPrice > 0)) {
            return false;
        }
        
        // 1. Heuristic: If we have Total Amount but NO Quantity and NO Unit Price, it's a Summary/Footer row.
        // Real items (even lump sum) should have Qty=1 or Price calculated.
        // Summary rows (like "Material Cost: 5000") matches this pattern: Q=0, P=0, Total=5000.
        if ($totalAmount !== null && $totalAmount > 0) {
            \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Footer (Summary detection: Total>0, Q=0, P=0). ItemName: '{$itemName}'");
            return true;
        }

        $footers = [
            'итого', 'всего', 'накладные расходы', 'сметная прибыль', 
            'материалы', 'машины и механизмы', // 'земляные работы', 'перевозка грузов' - REMOVED (they are valid sections)
            ' в базисных ценах', 'перевод цен', ' в смете', 'итоги по', 
            'ндс ', 'подпись', 'составил', 'проверил', 'утверждаю'
        ];
        
        $aiFooterKeywords = $this->sectionHints['footer_keywords'] ?? [];
        $allKeywords = array_merge($footers, array_map('mb_strtolower', $aiFooterKeywords));

        $text = mb_strtolower($itemName ?? '');
        if (preg_match('/(выполняемые|способом|по разделу|по позиции)/ui', $text) && $totalAmount > 0) {
            return true;
        }

        foreach ($rawData as $val) {
            $v = mb_strtolower(trim((string)$val));
            if (empty($v)) continue;
            
            // Check for signature lines with many underscores
            if (mb_substr_count($v, '_') > 5) {
                \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Footer (signature line). Value: '{$v}'");
                return true;
            }

            foreach ($allKeywords as $kw) {
                if (mb_stripos($v, $kw) !== false) {
                    \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Footer (keyword '{$kw}'). Value: '{$v}'");
                    return true;
                }
            }
        }
        
        // Extra check for "Smetnaya stoimost" and other specific footer phrases in the item name itself
        $textLower = mb_strtolower($itemName ?? '');
        $footerPhrases = [
            'сметная стоимость', 
            'составлен', 
            'проверил', 
            'сдал', 
            'принял',
            'локальный сметный расчет',
            'наименование работ и затрат'
        ];
        
        foreach ($footerPhrases as $phrase) {
            if (mb_stripos($textLower, $phrase) !== false) {
                 \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Footer (phrase '{$phrase}'). ItemName: '{$itemName}'");
                 return true;
            }
        }
        
        // Check for signature lines with underscores or dates in name
        if (mb_substr_count($textLower, '_') > 5) {
            \Illuminate\Support\Facades\Log::info("[ImportDebug] Row detected as Footer (signature underscores). ItemName: '{$itemName}'");
            return true;
        }

        return false;
    }

    private function truncate(mixed $value, int $limit): ?string
    {
        if ($value === null) return null;
        $strValue = (string)$value;
        if (mb_strlen($strValue) <= $limit) return $strValue;
        return mb_substr($strValue, 0, $limit);
    }

    private function applyInversePricing(array &$data): void
    {
        if (($data['baseUnitPrice'] ?? 0) > 0) {
            return;
        }

        $index    = $data['priceIndex'] ?? null;
        $total    = $data['currentTotalAmount'] ?? null;
        $qty      = $data['quantity'] ?? null;
        $current  = $data['currentUnitPrice'] ?? $data['unitPrice'] ?? null;

        if ($index !== null && $index > 0) {
            if ($current !== null && $current > 0) {
                $data['baseUnitPrice'] = round($current / $index, 4);
                Log::info("[ImportRowMapper] InversePricing: base={$data['baseUnitPrice']} from current={$current} / index={$index}");
                return;
            }

            if ($total !== null && $total > 0 && $qty !== null && $qty > 0) {
                $currentUnit = round($total / $qty, 4);
                $data['baseUnitPrice'] = round($currentUnit / $index, 4);
                Log::info("[ImportRowMapper] InversePricing: base={$data['baseUnitPrice']} from total={$total} / qty={$qty} / index={$index}");
            }
        }
    }
}
