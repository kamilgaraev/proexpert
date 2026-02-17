<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

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
            'code' => null,
            'sectionNumber' => null,
            'isSection' => false,
            'itemType' => 'work',
            'level' => 0,
            'sectionPath' => null,
            'rawData' => $rawData,
        ];

        foreach ($mapping as $field => $column) {
            $value = $this->getValueFromRaw($rawData, $column);
            
            switch ($field) {
                case 'name':
                case 'item_name':
                    $mappedData['itemName'] = $this->cleanName((string)$value);
                    break;
                case 'unit':
                    $mappedData['unit'] = (string)$value;
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
            }
        }

        // 2. Smart Name and Unit Splitting (if unit is null but name contains it)
        if (empty($mappedData['unit']) && !empty($mappedData['itemName'])) {
            $split = $this->splitNameAndUnit($mappedData['itemName']);
            $mappedData['itemName'] = $split['name'];
            $mappedData['unit'] = $split['unit'];
        }

        // 3. Robust Section Detection
        $mappedData['isSection'] = $this->isSection($mappedData['itemName'] ?? null, $rawData);

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
            itemName: $mappedData['itemName'] ?? '',
            unit: $mappedData['unit'] ?? null,
            quantity: $mappedData['quantity'] ?? null,
            unitPrice: $mappedData['unitPrice'] ?? null,
            code: $mappedData['code'] ?? null,
            isSection: $mappedData['isSection'] ?? false,
            itemType: $mappedData['itemType'] ?? 'work',
            level: $mappedData['level'] ?? 0,
            sectionPath: $mappedData['sectionPath'] ?? null,
            rawData: $rawData,
            currentTotalAmount: $mappedData['currentTotalAmount'] ?? null,
            isFooter: $this->isFooter($mappedData['itemName'] ?? null, $rawData),
            quantityCoefficient: $mappedData['quantityCoefficient'] ?? null,
            quantityTotal: $mappedData['quantityTotal'] ?? null,
            baseUnitPrice: $mappedData['baseUnitPrice'] ?? null,
            priceIndex: $mappedData['priceIndex'] ?? null,
            currentUnitPrice: $mappedData['currentUnitPrice'] ?? null,
            priceCoefficient: $mappedData['priceCoefficient'] ?? null
        );
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

        foreach ($nonEmpty as $val) {
            $valStr = trim((string)$val);
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
                if (mb_strlen($valStr) > 10) return false;
            }
        }

        // If high percentage of row is numeric and we found sequential numbers like 1, 2, 3...
        return ($sequentialCount >= 2 && $numericCount / count($nonEmpty) > 0.7);
    }

    private function isSection(?string $itemName, array $rawData): bool
    {
        // 0. If it's a footer, it's definitely not a section
        if ($this->isFooter($itemName, $rawData)) {
            return false;
        }

        // 1. Prioritize AI Hints
        $aiKeywords = $this->sectionHints['section_keywords'] ?? [];
        $aiCols = $this->sectionHints['section_columns'] ?? [];

        if (!empty($aiKeywords) || !empty($aiCols)) {
            // Check specific columns if AI pointed to them
            foreach ($aiCols as $colIdx) {
                if (isset($rawData[$colIdx])) {
                    $v = (string)$rawData[$colIdx];
                    foreach ($aiKeywords as $kw) {
                        if (mb_stripos($v, $kw) !== false) return true;
                    }
                }
            }
            
            // Or just check if any AI keyword appears in itemName
            $text = mb_strtolower($itemName ?? '');
            foreach ($aiKeywords as $kw) {
                if (mb_stripos($text, mb_strtolower($kw)) !== false) return true;
            }
        }

        // 2. Fallback to standard heuristics
        // Check all early columns for keywords
        foreach (array_slice($rawData, 0, 8) as $val) {
            $v = (string)$val;
            if (mb_stripos($v, 'раздел') !== false || mb_stripos($v, 'этап') !== false) {
                return true;
            }
        }

        $text = trim($itemName ?? '');
        
        // 2. Explicit keywords in itemName
        if (mb_stripos($text, 'раздел') !== false || mb_stripos($text, 'этап') !== false) {
            return true;
        }

        // 3. Pattern "1.1.2. Section Name"
        if (preg_match('/^[0-9]+(\.[0-9]+)+\s+[А-ЯA-Z]/u', $text)) {
            return true;
        }

        // 4. Heuristic: Check if row has only one long non-numeric string (likely a floating section title)
        $nonEmptyValues = array_filter($rawData, fn($v) => !empty(trim((string)$v)));
        if (count($nonEmptyValues) <= 3) {
            foreach ($nonEmptyValues as $val) {
                $v = (string)$val;
                if (mb_strlen($v) > 20 && !is_numeric($v)) {
                   // Ensure it's not a name column in a regular row by checking if quantity exists
                   // But since it's a count <= 3, it's very likely a technical or section row.
                   return true;
                }
            }
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
        // Take FIRST line for multi-line cells (e.g., 1,69 \n 1690/1000)
        $lines = explode("\n", $str);
        $firstLine = trim($lines[0]);

        // Basic cleaning
        $clean = str_replace([' ', "\xc2\xa0", "\xA0"], '', $firstLine);
        $clean = str_replace(',', '.', $clean);

        // If the line is mostly numeric, extract the number
        // This avoids extracting "1" from "Раздел 1"
        if (preg_match('/^-?[0-9]*\.?[0-9]+/', $clean, $matches)) {
            // Check if there are too many non-numeric chars in the first line
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
        // Take first line, ignore "Приказ Минстроя..." etc.
        $lines = explode("\n", $str);
        return trim($lines[0]);
    }

    private function splitNameAndUnit(string $name): array
    {
        $lines = explode("\n", $name);
        $bestUnit = null;
        $unitLineIndex = -1;

        // 1. Search for brackets in all lines
        foreach ($lines as $idx => $line) {
            if (preg_match_all('/[\(\[]([^\\(\\)\\[\\]]{1,30})[\)\]]/u', $line, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $candidate = trim($candidate);
                    
                    // Priority 1: Exact match with common units
                    if (in_array(mb_strtolower($candidate), self::COMMON_UNITS)) {
                        $bestUnit = $candidate;
                        $unitLineIndex = $idx;
                        break 2;
                    }
                    
                    // Priority 2: Contains common unit (e.g. "100 м3")
                    foreach (self::COMMON_UNITS as $common) {
                        if (mb_stripos($candidate, $common) !== false) {
                            $bestUnit = $candidate;
                            $unitLineIndex = $idx;
                        }
                    }
                    
                    // Priority 3: First bracketed item that looks like a unit (short, contains letters)
                    if (!$bestUnit && mb_strlen($candidate) < 10 && preg_match('/[а-яёa-z]/ui', $candidate)) {
                        $bestUnit = $candidate;
                        $unitLineIndex = $idx;
                    }
                }
            }
        }

        if ($bestUnit) {
            // Remove the line if it contained only the unit (often GrandSmeta format)
            if ($unitLineIndex !== -1 && trim(preg_replace('/[\(\[]' . preg_quote($bestUnit, '/') . '[\)\]]/u', '', $lines[$unitLineIndex])) === '') {
                 unset($lines[$unitLineIndex]);
            }
            return [
                'name' => trim(implode("\n", $lines)),
                'unit' => $bestUnit
            ];
        }

        // 2. Try to find unit at the end of the first line (e.g. "Name, м2")
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

    private function isFooter(?string $itemName, array $rawData): bool
    {
        $text = mb_strtolower($itemName ?? '');
        
        $aiFooterKeywords = $this->sectionHints['footer_keywords'] ?? [];
        if (!empty($aiFooterKeywords)) {
            foreach ($aiFooterKeywords as $kw) {
                if (mb_stripos($text, mb_strtolower($kw)) !== false) return true;
            }
        }

        // Standard heuristics for footers
        $footers = ['итого', 'всего', 'накладные', 'сметная прибыль', 'справочно', 'в базисных ценах', 'перевод цен', 'смете'];
        foreach ($footers as $f) {
            if (mb_stripos($text, $f) !== false) return true;
        }

        // Additional check: if itemName looks like a long footer without keywords but it's high column
        // but let's stick to keywords for now to avoid false positives.

        return false;
    }
}
