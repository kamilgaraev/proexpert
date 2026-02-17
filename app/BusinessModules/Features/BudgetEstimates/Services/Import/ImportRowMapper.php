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

        // 1. Fill from Mapping
        foreach ($mapping as $field => $column) {
            if ($column === null && $field !== 'name' && $field !== 'item_name') continue;
            
            $value = $this->getValueFromRaw($rawData, $column);
            
            switch ($field) {
                case 'name':
                case 'item_name':
                    $mappedData['itemName'] = $this->cleanName((string)$value);
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
                if (mb_strlen($v) > 5 && !is_numeric($v)) {
                    $mappedData['itemName'] = $v;
                    break;
                }
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
            isFooter: $mappedData['isFooter'] ?? false,
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
            // Removed check for 'раздел' etc. here because they are valid sections, not technical rows to skip.
        }

        $text = trim($itemName ?? '');
        // Removed check for 'раздел' etc. here too.

        // 4. Pattern "1.1.2. Section Name"
        if (preg_match('/^[0-9]+(\.[0-9]+)+\s+[А-ЯA-Z]/u', $text)) {
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
        // 0. Safety: real items MUST have quantity > 0 or price > 0. 
        // Summary rows often have totalAmount but NO quantity or unitPrice.
        // We ignore unit here because summary rows sometimes carry a "fake" unit or "ед" from mapping.
        if (($quantity !== null && $quantity > 0) || 
            ($unitPrice !== null && $unitPrice > 0)) {
            return false;
        }

        $footers = [
            'итого', 'всего', 'накладные расходы', 'сметная прибыль', 
            'материалы', 'машины и механизмы', 'земляные работы', 'перевозка грузов',
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
            
            foreach ($allKeywords as $kw) {
                if (mb_stripos($v, $kw) !== false) return true;
            }
        }

        return false;
    }
}
