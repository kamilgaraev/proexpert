<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

class ImportRowMapper
{
    /**
     * Map raw row data to EstimateImportRowDTO based on column mapping.
     */
    public function map(EstimateImportRowDTO $rowDTO, array $mapping): EstimateImportRowDTO
    {
        $rawData = $rowDTO->rawData;
        if (empty($rawData) || empty($mapping)) {
            return $rowDTO;
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
                    $mappedData['itemName'] = (string)$value;
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

        // 3. Section detection heuristic
        // If name exists but no quantity/price, it's likely a section or a comment
        if (!empty($mappedData['itemName']) && $mappedData['quantity'] === null && $mappedData['unitPrice'] === null) {
            // Check if it looks like "Раздел..." or just a header
            if ($this->isSection($mappedData['itemName'], $rawData)) {
                $mappedData['isSection'] = true;
                // Clear numeric fields for sections to avoid "Раздел 1" grabbing "1" as price
                $mappedData['quantity'] = null;
                $mappedData['unitPrice'] = null;
                $mappedData['currentTotalAmount'] = null;
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
            quantityCoefficient: $mappedData['quantityCoefficient'] ?? null,
            quantityTotal: $mappedData['quantityTotal'] ?? null,
            baseUnitPrice: $mappedData['baseUnitPrice'] ?? null,
            priceIndex: $mappedData['priceIndex'] ?? null,
            currentUnitPrice: $mappedData['currentUnitPrice'] ?? null,
            priceCoefficient: $mappedData['priceCoefficient'] ?? null
        );
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

    private function isSection(string $itemName, array $rawData): bool
    {
        // Check if it looks like "Раздел..." or "ИТОГО..."
        if (mb_stripos($itemName, 'раздел') !== false || mb_stripos($itemName, 'итого') !== false) {
            return true;
        }

        // Heuristic: If the first non-empty cell is a long string, and other cells are empty
        $nonEmptyCells = array_filter($rawData, fn($v) => $v !== null && $v !== '');
        if (count($nonEmptyCells) === 1 && mb_strlen($itemName) > 15) {
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
        // 1. Try to find unit in brackets at the end: "Name (unit)"
        if (preg_match('/^(.*?)\s*[\(\[]([^0-9\(\)\[\]]{1,20})[\)\]]\s*$/u', $name, $matches)) {
            return [
                'name' => trim($matches[1]),
                'unit' => trim($matches[2])
            ];
        }

        // 2. Try multi-line split if last line is short (typical unit position)
        $lines = explode("\n", $name);
        if (count($lines) >= 2) {
            $lastLine = trim(end($lines));
            if (mb_strlen($lastLine) > 1 && mb_strlen($lastLine) < 15 && !preg_match('/[0-9]{3,}/', $lastLine)) {
                array_pop($lines);
                return [
                    'name' => trim(implode("\n", $lines)),
                    'unit' => $lastLine
                ];
            }
        }

        return ['name' => $name, 'unit' => null];
    }
}
