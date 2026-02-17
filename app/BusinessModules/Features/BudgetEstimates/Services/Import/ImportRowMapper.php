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
            $mappedData['unit'] = $mappedData['unit'] ?: $split['unit'];
        }

        // 3. Robust Section Detection
        $mappedData['isFooter'] = $this->isFooter(
            $mappedData['itemName'] ?? null, 
            $rawData,
            $mappedData['quantity'] ?? null,
            $mappedData['unitPrice'] ?? null,
            $mappedData['unit'] ?? null
        );

        $mappedData['isSection'] = false;
        if (!$mappedData['isFooter']) {
            $mappedData['isSection'] = $this->isSection(
                $mappedData['itemName'] ?? null, 
                $rawData,
                $mappedData['quantity'] ?? null,
                $mappedData['unitPrice'] ?? null,
                $mappedData['unit'] ?? null
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

    private function isSection(?string $itemName, array $rawData, ?float $quantity = null, ?float $unitPrice = null, ?string $unit = null): bool
    {
        // 0. Safety: items with quantity, price or unit are NOT sections
        if (($quantity !== null && $quantity > 0) || ($unitPrice !== null && $unitPrice > 0) || !empty($unit)) {
            return false;
        }

        // 1. If it's a footer, it's definitely not a section
        if ($this->isFooter($itemName, $rawData)) {
            return false;
        }

        // 2. Prioritize AI Hints
        $aiKeywords = $this->sectionHints['section_keywords'] ?? [];
        $aiCols = $this->sectionHints['section_columns'] ?? [];

        if (!empty($aiKeywords) || !empty($aiCols)) {
            foreach ($aiCols as $colIdx) {
                if (isset($rawData[$colIdx])) {
                    $v = (string)$rawData[$colIdx];
                    foreach ($aiKeywords as $kw) {
                        if (mb_stripos($v, $kw) !== false) return true;
                    }
                }
            }
            
            $text = mb_strtolower($itemName ?? '');
            foreach ($aiKeywords as $kw) {
                if (mb_stripos($text, mb_strtolower($kw)) !== false) return true;
            }
        }

        // 3. Fallback to standard heuristics
        foreach (array_slice($rawData, 0, 8) as $val) {
            $v = trim((string)$val);
            if (empty($v)) continue;
            // Only match at start of string for "Раздел" or "Этап"
            if (preg_match('/^(раздел|этап|глава|пп|п\.п\.)\s*[0-9]*/ui', $v)) {
                return true;
            }
        }

        $text = trim($itemName ?? '');
        if (preg_match('/^(раздел|этап|глава|пп|п\.п\.)\s*[0-9]*/ui', $text)) {
            return true;
        }

        // 4. Pattern "1.1.2. Section Name"
        if (preg_match('/^[0-9]+(\.[0-9]+)+\s+[А-ЯA-Z]/u', $text)) {
            return true;
        }

        // 5. Technical row check: if row has only one long non-numeric string and NO numeric data
        $nonEmptyValues = array_filter($rawData, fn($v) => !empty(trim((string)$v)));
        if (count($nonEmptyValues) <= 2) {
            foreach ($nonEmptyValues as $val) {
                $v = (string)$val;
                if (mb_strlen($v) > 25 && !is_numeric($v)) {
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

    private function isFooter(?string $itemName, array $rawData, ?float $quantity = null, ?float $unitPrice = null, ?string $unit = null): bool
    {
        // 0. Safety: items with quantity, price or unit are NOT footers
        if (($quantity !== null && $quantity > 0) || ($unitPrice !== null && $unitPrice > 0) || !empty($unit)) {
            return false;
        }

        $footers = [
            'итого по', 'всего по', 'накладные расходы', 'сметная прибыль', 'справочно', 
            'в базисных ценах', 'перевод цен', ' в смете', 'итоги по', 
            'ндс ', 'составил', 'проверил', 'утверждаю'
        ];
        
        $aiFooterKeywords = $this->sectionHints['footer_keywords'] ?? [];
        $allKeywords = array_merge($footers, array_map('mb_strtolower', $aiFooterKeywords));

        foreach ($rawData as $val) {
            $v = mb_strtolower(trim((string)$val));
            if (empty($v)) continue;
            
            foreach ($allKeywords as $kw) {
                // More precise matching for short keywords
                if (mb_strlen($kw) < 4) {
                    if ($v === $kw) return true;
                } else {
                    if (mb_stripos($v, $kw) !== false) return true;
                }
            }
        }

        return false;
    }
}
