<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class EstimateImportDTO
{
    public function __construct(
        public string $fileName,
        public int $fileSize,
        public string $fileFormat,
        public array $sections,
        public array $items,
        public array $totals,
        public array $metadata,
        public ?array $detectedColumns = null,
        public ?array $rawHeaders = null,
        public ?string $estimateType = null,        // Тип сметы: 'grandsmeta', 'rik', 'fer', 'smartsmeta', 'custom'
        public ?float $typeConfidence = null,       // Уверенность в определении типа (0-100)
        public array $validationSummary = []        // Сводка по ошибкам и предупреждениям
    ) {}
    
    public function toArray(): array
    {
        // Преобразуем items из объектов EstimateImportRowDTO в массивы
        $itemsArray = array_map(function($item) {
            if ($item instanceof EstimateImportRowDTO) {
                return $item->toArray();
            }
            return $item; // Уже массив
        }, $this->items);
        
        return [
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'file_format' => $this->fileFormat,
            'sections' => $this->sections,
            'items' => $itemsArray,
            'totals' => $this->totals,
            'metadata' => $this->metadata,
            'detected_columns' => $this->detectedColumns,
            'raw_headers' => $this->rawHeaders,
            'estimate_type' => $this->estimateType,
            'type_confidence' => $this->typeConfidence,
            'validation_summary' => $this->validationSummary,
        ];
    }
    
    public function getItemsCount(): int
    {
        return count($this->items);
    }
    
    public function getSectionsCount(): int
    {
        return count($this->sections);
    }
    
    public function getTotalAmount(): float
    {
        return $this->totals['total_amount'] ?? 0.0;
    }
    
    public function getUnmatchedItems(): array
    {
        return array_filter($this->items, function($item) {
            // Check array or object
            if (is_array($item)) {
                return empty($item['matched_work_type_id'] ?? null);
            }
            return false; // DTO doesn't have matched_work_type_id yet
        });
    }
}
