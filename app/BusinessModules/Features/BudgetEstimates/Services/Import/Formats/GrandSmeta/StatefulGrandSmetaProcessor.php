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
            // Attach as resource to current position
            $resourceDTO = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            $this->currentPosition->subItems[] = $resourceDTO;
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
            'items' => $this->items,
            'sections' => $this->sections
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
        $dto = new EstimateImportRowDTO();
        $dto->rowNumber = $rowNumber;
        $dto->isSection = $isSection;
        $dto->itemName = (string)($data[$mapping['name'] ?? ''] ?? '');
        $dto->code = (string)($data[$mapping['code'] ?? ''] ?? '');
        $dto->unit = (string)($data[$mapping['unit'] ?? ''] ?? '');
        $dto->quantity = $this->parseFloat($data[$mapping['quantity'] ?? ''] ?? 0);
        $dto->unitPrice = $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0);
        
        return $dto;
    }

    private function parseFloat(mixed $value): float
    {
        if (is_numeric($value)) return (float)$value;
        $clean = str_replace([',', ' '], ['.', ''], (string)$value);
        return is_numeric($clean) ? (float)$clean : 0.0;
    }
}
