<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Handles hierarchical parsing of GrandSmeta (LSR 421) files.
 * Tracks "State" to group resources under positions.
 */
class StatefulGrandSmetaProcessor
{
    private ?EstimateImportRowDTO $currentPosition = null;
    private array $items = [];
    private array $sections = [];
    private bool $inFooter = false;
    private array $footerData = [];

    /**
     * Process a single row from GrandSmeta.
     */
    public function processRow(array $rowData, array $mapping, int $rowNumber): void
    {
        $name = (string)($rowData[$mapping['name'] ?? ''] ?? '');
        $code = (string)($rowData[$mapping['code'] ?? ''] ?? '');
        $posNo = trim((string)($rowData[$mapping['position_number'] ?? ''] ?? ''));
        
        // Fallback for Name: sections often start in the first column
        if (empty(trim($name))) {
            $firstCol = array_key_first($rowData);
            $potentialName = (string)($rowData[$firstCol] ?? '');
            if (!empty(trim($potentialName)) && $this->isSection($potentialName)) {
                $name = $potentialName;
            }
        }

        $nameLower = mb_strtolower(trim($name));

        // --- Footer Detection ---
        if (str_starts_with($nameLower, 'итоги по смете')) {
            $this->closeCurrentPosition();
            $this->inFooter = true;
            return;
        }

        if ($this->inFooter) {
            $this->processFooterRow($name, $rowData, $mapping);
            return;
        }

        // 0. Special Handling for "Total per Position" (Всего по позиции)
        // GrandSmeta often puts money in this row instead of the main row.
        if (str_contains($nameLower, 'всего по позиции')) {
            if ($this->currentPosition) {
                $money = $this->extractMoney($rowData, $mapping);
                $this->currentPosition->unitPrice = $money['unit_price'] ?: $this->currentPosition->unitPrice;
                $this->currentPosition->currentTotalAmount = $money['total_price'] ?: $this->currentPosition->currentTotalAmount;
            }
            $this->closeCurrentPosition();
            return;
        }

        // 1. Skip other summary rows but capture their context if needed
        if (str_contains($nameLower, 'всего по') || $nameLower === 'итого') {
            $this->closeCurrentPosition();
            return;
        }

        // 2. Identify Row Type
        $isSection = $this->isSection($name);
        $isResource = !$isSection && $this->isResource($rowData, $mapping);
        $isPosition = !$isSection && !$isResource && $this->isPosition($posNo, $name, $code);

        if ($isSection) {
            $this->closeCurrentPosition();
            $this->sections[] = $this->mapToDTO($rowData, $mapping, $rowNumber, true);
            return;
        }

        if ($isPosition) {
            $this->closeCurrentPosition();
            $this->currentPosition = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            // ⭐ Add position immediately to maintain Parent -> Children order
            $this->items[] = $this->currentPosition;
            return;
        }

        // Catch-all for anything inside a position: if we have a current position, everything else is a sub-item
        // unless it's a section or a new position.
        if ($this->currentPosition && !$isSection && !$isPosition) {
            $subItemDTO = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            $subItemDTO->isSubItem = true;
            $this->items[] = $subItemDTO;
            return;
        }
    }

    private function extractMoney(array $data, array $mapping): array
    {
        return [
            'unit_price' => $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0),
            'total_price' => $this->parseFloat($data[$mapping['total_price'] ?? ''] ?? 0)
        ];
    }

    private function closeCurrentPosition(): void
    {
        // Now it's just a cleanup, since the item is already in $this->items.
        // We might want to "lock" it or just reset the pointer.
        $this->currentPosition = null;
    }

    public function getResult(): array
    {
        $this->closeCurrentPosition();
        
        return [
            'items' => $this->items,
            'sections' => $this->sections,
            'footer' => $this->footerData
        ];
    }
    
    public function reset(): void
    {
        $this->currentPosition = null;
        $this->items = [];
        $this->sections = [];
        $this->inFooter = false;
        $this->footerData = [];
    }

    private function processFooterRow(string $name, array $rowData, array $mapping): void
    {
        if (empty(trim($name))) {
            return;
        }
        
        // Extract value from the last possible column (total_price usually)
        $val = $this->parseFloat($rowData[$mapping['total_price'] ?? ''] ?? 0);
        
        $cleanName = mb_strtolower(trim($name));
        
        // Capture specific totals as per LSR/GrandSmeta standard
        if (str_starts_with($cleanName, 'итого прямые затраты')) {
            $this->footerData['direct_costs'] = $val;
        } elseif (str_starts_with($cleanName, 'оплата труда рабочих') || $cleanName === 'оплата труда') {
            // Aggregate if there are multiple parts
            $this->footerData['labor_cost'] = ($this->footerData['labor_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'материалы')) {
            $this->footerData['materials_cost'] = ($this->footerData['materials_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'накладные расходы') || str_starts_with($cleanName, 'итого накладные')) {
            $this->footerData['overhead_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'сметная прибыль') || str_starts_with($cleanName, 'итого сметная')) {
            $this->footerData['profit_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'оборудование')) {
            $this->footerData['equipment_cost'] = ($this->footerData['equipment_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'всего по смете')) {
            $this->footerData['total_estimate_cost'] = $val;
        }
    }

    // --- Helper Detection Logic ---

    private function isSection(string $name): bool
    {
        $name = trim($name);
        if (empty($name)) return false;

        // "Раздел 1", "Глава 2", но NOT "Объектовая станция"
        // Use word boundaries \b to match exact words
        $sectionPattern = '/^(Раздел|Смета|Объект|Глава|Этап|Комплекс|Локальный|I+|V+|X+)\b/iu';
        
        return (bool)preg_match($sectionPattern, $name) || 
               (bool)preg_match('/^\d+(\.\d+)*\.?\s+[А-ЯA-Z]/u', $name);
    }

    private function isResource(array $data, array $mapping): bool
    {
        $name = mb_strtolower(trim((string)($data[$mapping['name'] ?? ''] ?? '')));
        $unit = mb_strtolower(trim((string)($data[$mapping['unit'] ?? ''] ?? '')));
        $code = mb_strtolower(trim((string)($data[$mapping['code'] ?? ''] ?? '')));
        
        // "Вспомогательные материальные ресурсы" и т.д.
        if (str_contains($name, 'вспомогательные') && str_contains($name, 'ресурсы')) {
            return true;
        }

        // GrandSmeta markers like "М", "ОТ", "ЗП"...
        if (in_array($name, ['м', 'от', 'зп', 'эм', 'зт', 'от(зт)'], true)) {
            return true;
        }

        // Codes starting with specific resource markers
        if (preg_match('/^(01\.|С|ТСЦ|ФССЦ|ОТ|ЗП)/u', $code)) {
            return true;
        }

        if (empty($unit)) return false;

        // Specific resource-only units. We exclude 'шт', 'м', 'м3' because positions use them too.
        $resourceUnits = ['чел.-ч', 'чел-ч', 'маш.-ч', 'квт-ч', '%'];
        
        foreach ($resourceUnits as $ru) {
            if ($unit === $ru || str_starts_with($unit, $ru)) return true;
        }

        return false;
    }

    private function isPosition(string $posNo, string $name, string $code): bool
    {
        $posNo = trim($posNo);
        if (empty($posNo)) return false;

        // If it looks like a resource marker, it's NOT a new position
        $lowerName = mb_strtolower($name);
        if (in_array($lowerName, ['м', 'от', 'зп', 'эм', 'зт', 'от(зт)'], true)) {
            return false;
        }

        // Позиции в ГрандСмете (ЛСР 421) обычно:
        // 1. Просто число ("1", "2")
        // 2. Число с буквой ("2О", "15А", "4О")
        if (preg_match('/^\d+[А-ЯA-Z]?$/ui', $posNo)) {
            // Если есть код, это позиция. Если кода нет, это может быть заголовок.
            return !empty(trim($code));
        }

        return false;
    }

    private function isFooterMarker(string $name): bool
    {
        $nameLower = mb_strtolower(trim($name));
        
        // "Всего по позиции" is NOT a global footer marker
        if (str_contains($nameLower, 'всего по позиции')) {
            return false;
        }

        return str_starts_with($nameLower, 'итоги по смете') || 
               str_contains($nameLower, 'сметная стоимость') ||
               $nameLower === 'итого';
    }

    private function mapToDTO(array $data, array $mapping, int $rowNumber, bool $isSection): EstimateImportRowDTO
    {
        $qty = $this->parseFloat($data[$mapping['quantity'] ?? ''] ?? 0);
        $price = $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0);
        $total = $this->parseFloat($data[$mapping['total_price'] ?? ''] ?? 0);
        $name = trim((string)($data[$mapping['name'] ?? ''] ?? ''));
        $code = trim((string)($data[$mapping['code'] ?? ''] ?? ''));

        // In GrandSmeta unit price column might be empty if price is only in total
        if ($price <= 0 && $qty > 0 && $total > 0) {
            $price = $total / $qty;
        }

        // Detect Item Type
        $itemType = 'work';
        $lowerName = mb_strtolower($name);
        $lowerCode = mb_strtolower($code);

        if (str_contains($lowerName, 'труд') || str_contains($lowerName, 'от(') || str_starts_with($lowerCode, 'от')) {
            $itemType = 'labor';
        } elseif (str_contains($lowerName, 'маш.') || str_contains($lowerName, 'механизм') || str_starts_with($lowerCode, 'эм')) {
            $itemType = 'machinery';
        } elseif (str_contains($lowerName, 'материал') || preg_match('/^(01\.|с|тсц|фссц)/u', $lowerCode)) {
            $itemType = 'material';
        }

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: (string)($data[$mapping['position_number'] ?? ''] ?? ''),
            itemName: $name,
            unit: (string)($data[$mapping['unit'] ?? ''] ?? ''),
            quantity: $qty > 0 ? $qty : null,
            unitPrice: $price > 0 ? $price : null,
            code: $code,
            isSection: $isSection,
            itemType: $itemType,
            currentTotalAmount: $total > 0 ? $total : null,
            rawData: $data
        );
    }

    private function parseFloat(mixed $value): float
    {
        if (is_numeric($value)) return (float)$value;
        if (empty($value)) return 0.0;

        // Handle Russian formatting (space as thousands, comma as fractional)
        $clean = str_replace([' ', "\xc2\xa0"], '', (string)$value);
        $clean = str_replace(',', '.', $clean);
        
        // Extract first numeric part (to handle things like "100 шт" if misplaced)
        if (preg_match('/-?\d+(\.\d+)?/', $clean, $matches)) {
            return (float)$matches[0];
        }

        return 0.0;
    }
}
