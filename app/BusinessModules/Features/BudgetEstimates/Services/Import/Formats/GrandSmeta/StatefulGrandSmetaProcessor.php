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

    /**
     * Process a single row from GrandSmeta.
     */
    public function processRow(array $rowData, array $mapping, int $rowNumber): void
    {
        // 1. Identify Row Type (Section, Position, or Resource)
        $isSection = $this->isSection($rowData, $mapping);
        $isPosition = $this->isPosition($rowData, $mapping);
        $isResource = $this->isResource($rowData, $mapping);

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
            // Add as sub-item to the flat list
            $resourceDTO = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            $resourceDTO->isSubItem = true;
            $this->items[] = $resourceDTO;
            return;
        }
        
        // If it's something else (e.g., "Total for position" row), we might just ignore it
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
            'sections' => array_map(fn($section) => $section->toArray(), $this->sections)
        ];
    }

    // --- Helper Detection Logic ---

    private function isSection(array $data, array $mapping): bool
    {
        $name = $data[$mapping['name'] ?? ''] ?? '';
        return (bool)preg_match('/Раздел\s+\d+/iu', (string)$name);
    }

    private function isPosition(array $data, array $mapping): bool
    {
        $code = $data[$mapping['code'] ?? ''] ?? '';
        // GrandSmeta positions usually have a complex code (e.g., TЦ-..., ГЭСН...)
        return (bool)preg_match('/^[A-ZА-Я0-9-._]+$/u', (string)$code) && !empty($code);
    }

    private function isResource(array $data, array $mapping): bool
    {
        $unit = $data[$mapping['unit'] ?? ''] ?? '';
        // Resources often have specific units like чел.-ч, кг, м3
        return in_array(mb_strtolower((string)$unit), ['чел.-ч', 'маш.-ч', 'кг', 'т', 'м3', 'шт']);
    }

    private function mapToDTO(array $data, array $mapping, int $rowNumber, bool $isSection): EstimateImportRowDTO
    {
        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: null, // GrandSmeta handles sections via row logic
            itemName: (string)($data[$mapping['name'] ?? ''] ?? ''),
            unit: (string)($data[$mapping['unit'] ?? ''] ?? ''),
            quantity: $this->parseFloat($data[$mapping['quantity'] ?? ''] ?? 0),
            unitPrice: $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0),
            code: (string)($data[$mapping['code'] ?? ''] ?? ''),
            isSection: $isSection,
            rawData: $data
        );
    }

    private function parseFloat(mixed $value): float
    {
        if (is_numeric($value)) return (float)$value;
        $clean = str_replace([',', ' '], ['.', ''], (string)$value);
        return is_numeric($clean) ? (float)$clean : 0.0;
    }
}
