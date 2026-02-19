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
        
        // --- Footer Detection ---
        if (str_starts_with(mb_strtolower(trim($name)), 'итоги по смете')) {
            $this->closeCurrentPosition();
            $this->inFooter = true;
            return;
        }

        if ($this->inFooter) {
            $this->processFooterRow($name, $rowData, $mapping);
            return;
        }

        // 0. Skip summary rows
        if (str_contains(mb_strtolower($name), 'всего по') || mb_strtolower(trim($name)) === 'итого') {
            $this->closeCurrentPosition();
            return;
        }

        // 1. Identify Row Type
        $isSection = $this->isSection($name);
        // Positions usually have an integer in the № п/п column
        $isPosition = !empty($posNo) && preg_match('/^\d+$/', $posNo); 
        $isResource = !$isSection && !$isPosition && $this->isResource($rowData, $mapping);

        if ($isSection) {
            $this->closeCurrentPosition();
            $this->sections[] = $this->mapToDTO($rowData, $mapping, $rowNumber, true);
            return;
        }

        if ($isPosition) {
            $this->closeCurrentPosition();
            $this->currentPosition = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            return;
        }

        if ($isResource && $this->currentPosition) {
            $resourceDTO = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            $resourceDTO->isSubItem = true;
            $this->items[] = $resourceDTO;
            return;
        }
    }

    private function closeCurrentPosition(): void
    {
        if ($this->currentPosition) {
            $this->items[] = $this->currentPosition;
            $this->currentPosition = null;
        }
    }

    public function getResult(): array
    {
        $this->closeCurrentPosition();
        
        return [
            'items' => array_map(fn($item) => $item->toArray(), $this->items),
            'sections' => array_map(fn($section) => $section->toArray(), $this->sections),
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
        return (bool)preg_match('/(Раздел|Смета|Объект)\s+(\d+|\w+)/iu', $name);
    }

    private function isResource(array $data, array $mapping): bool
    {
        $unit = mb_strtolower(trim((string)($data[$mapping['unit'] ?? ''] ?? '')));
        if (empty($unit)) return false;

        $resourceUnits = ['чел.-ч', 'маш.-ч', 'кг', 'т', 'м3', 'шт', 'компл', 'м', 'м2', 'квт-ч'];
        
        foreach ($resourceUnits as $ru) {
            if (str_contains($unit, $ru)) return true;
        }

        return false;
    }

    private function mapToDTO(array $data, array $mapping, int $rowNumber, bool $isSection): EstimateImportRowDTO
    {
        $qty = $this->parseFloat($data[$mapping['quantity'] ?? ''] ?? 0);
        $price = $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0);
        $total = $this->parseFloat($data[$mapping['total_price'] ?? ''] ?? 0);

        // In GrandSmeta unit price column might be empty if price is only in total
        if ($price <= 0 && $qty > 0 && $total > 0) {
            $price = $total / $qty;
        }

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: (string)($data[$mapping['position_number'] ?? ''] ?? ''),
            itemName: trim((string)($data[$mapping['name'] ?? ''] ?? '')),
            unit: (string)($data[$mapping['unit'] ?? ''] ?? ''),
            quantity: $qty,
            unitPrice: $price,
            code: (string)($data[$mapping['code'] ?? ''] ?? ''),
            isSection: $isSection,
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
