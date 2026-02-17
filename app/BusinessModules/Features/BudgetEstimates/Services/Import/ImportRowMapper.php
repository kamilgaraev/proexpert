<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class ImportRowMapper
{
    private array $sectionHints = [];

    private const COMMON_UNITS = [
        'шт', 'м', 'кг', 'т', 'м3', 'м2', 'км', 'чел.-ч', 'маш.-час', 'компл', 'компл.', 'ед', 'пог. м', 'пог.м', '100 м', '1000 м3', '100 м2', '100 м3', 'тн', 'усл. ед', 'уп'
    ];

    public function setSectionHints(array $hints): void
    {
        $this->sectionHints = $hints;
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
                    $mappedData['unitPrice'] = $this->parseFloat($value);
                    break;
                case 'current_total_amount':
                case 'total_amount':
                    $mappedData['currentTotalAmount'] = $this->parseFloat($value);
                    break;
                case 'code':
                case 'normative_rate_code':
                    $mappedData['code'] = $this->cleanCode($value);
                    break;
                case 'section_number':
                    $mappedData['sectionNumber'] = (string)$value;
                    break;
                case 'quantity_coefficient':
                    $mappedData['quantityCoefficient'] = $this->parseFloat($value);
                    break;
                case 'quantity_total':
                    $mappedData['quantityTotal'] = $this->parseFloat($value);
                    break;
                case 'base_unit_price':
                    $mappedData['baseUnitPrice'] = $this->parseFloat($value);
                    break;
                case 'price_index':
                    $mappedData['priceIndex'] = $this->parseFloat($value);
                    break;
                case 'current_unit_price':
                    $mappedData['currentUnitPrice'] = $this->parseFloat($value);
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
                // Store detected index
                $mappedData['priceIndex'] = $attributes['price_index'];
                
                // If we have a unit price (which is Base Price), we move it to baseUnitPrice
                // and calculate new Unit Price (Current Price)
                if (!empty($mappedData['unitPrice'])) {
                    $mappedData['baseUnitPrice'] = $mappedData['unitPrice'];
                    $mappedData['unitPrice'] = round($mappedData['baseUnitPrice'] * $mappedData['priceIndex'], 2);
                    
                    // Recalculate Current Total Amount if we have quantity
                    if (!empty($mappedData['quantity'])) {
                        $mappedData['currentTotalAmount'] = round($mappedData['unitPrice'] * $mappedData['quantity'], 2);
                    }
                    
                    \Illuminate\Support\Facades\Log::info("[ImportRowMapper] Smart Parsing applied: BasePrice={$mappedData['baseUnitPrice']} * Index={$mappedData['priceIndex']} = NewPrice={$mappedData['unitPrice']}");
                }
            }
            
            // Store detected rates
            if ($attributes['overhead_rate'] !== null) {
                $mappedData['overheadRate'] = $attributes['overhead_rate'];
            }
            if ($attributes['profit_rate'] !== null) {
                $mappedData['profitRate'] = $attributes['profit_rate'];
            }
            
            if ($attributes['overhead_rate'] || $attributes['profit_rate']) {
                 \Illuminate\Support\Facades\Log::info("[ImportRowMapper] Rates detected and stored: NR={$attributes['overhead_rate']}%, SP={$attributes['profit_rate']}%");
            }
        }

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
                $mappedData['currentTotalAmount']
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
            currentTotalAmount: $mappedData['currentTotalAmount'] ?? null,
            isFooter: $mappedData['isFooter'] ?? false,
            quantityCoefficient: $mappedData['quantityCoefficient'] ?? null,
            quantityTotal: $mappedData['quantityTotal'] ?? null,
            baseUnitPrice: $mappedData['baseUnitPrice'] ?? null,
            priceIndex: $mappedData['priceIndex'] ?? null,
            currentUnitPrice: $mappedData['currentUnitPrice'] ?? null,
            priceCoefficient: $mappedData['priceCoefficient'] ?? null,
            overheadRate: $mappedData['overheadRate'] ?? null,
            profitRate: $mappedData['profitRate'] ?? null
        );
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
        // Amount: "НР ... ( 123,45 руб )"
        if (preg_match('/(?:^|[^а-яёa-z])(?:нр|накладные)(?![а-яёa-z]).*?\(\s*(\d+[.,]?\d*)\s*(?:руб|р)/ui', $text, $matches)) {
             $attributes['overhead_amount'] = (float)str_replace(',', '.', $matches[1]);
        }
        
        // Rate: "НР ... 130%"
        if (preg_match('/(?:^|[^а-яёa-z])(?:нр|накладные)(?![а-яёa-z]).*?(\d+[.,]?\d*)\s*%/ui', $text, $matches)) {
             $attributes['overhead_rate'] = (float)str_replace(',', '.', $matches[1]);
        }

        // 3. Parse Profit (СП)
        // Fix for "справочно" matching "сп": Ensure SP is a whole word.
        // Amount: "СП ... ( 123,45 руб )"
        if (preg_match('/(?:^|[^а-яёa-z])(?:сп|сметная)(?![а-яёa-z]).*?\(\s*(\d+[.,]?\d*)\s*(?:руб|р)/ui', $text, $matches)) {
             $attributes['profit_amount'] = (float)str_replace(',', '.', $matches[1]);
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

    private function isSection(?string $itemName, array $rawData, ?float $quantity = null, ?float $unitPrice = null, ?string $unit = null, ?float $totalAmount = null): bool
    {
        // 0. Safety: items with ANY numeric data or ANY unit are NOT sections
        if (($quantity !== null && $quantity > 0) || 
            ($unitPrice !== null && $unitPrice > 0) || 
            ($totalAmount !== null && $totalAmount > 0) ||
            !empty($unit)) {
            return false;
        }

        // 1. If it's a footer, it's definitely not a section (recursive call with context is fine)
        if ($this->isFooter($itemName, $rawData, $quantity, $unitPrice, $unit, $totalAmount)) {
            return false;
        }

        // 2. Prioritize AI Hints (only if we have no clear data above)
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

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) return null;
        if (mb_strlen($value) <= $limit) return $value;
        return mb_substr($value, 0, $limit);
    }
}
