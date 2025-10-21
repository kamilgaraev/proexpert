<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class EstimateImportRowDTO
{
    public function __construct(
        public int $rowNumber,
        public ?string $sectionNumber,
        public string $itemName,
        public ?string $unit,
        public ?float $quantity,
        public ?float $unitPrice,
        public ?string $code,
        public bool $isSection,
        public string $itemType = 'work',
        public int $level = 0,
        public ?string $sectionPath = null,
        public ?array $rawData = null
    ) {}
    
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'section_number' => $this->sectionNumber,
            'item_name' => $this->itemName,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'code' => $this->code,
            'is_section' => $this->isSection,
            'item_type' => $this->itemType,
            'level' => $this->level,
            'section_path' => $this->sectionPath,
            'raw_data' => $this->rawData,
        ];
    }
    
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->itemName)) {
            $errors[] = "Строка {$this->rowNumber}: отсутствует наименование";
        }
        
        if (!$this->isSection) {
            if ($this->quantity === null || $this->quantity <= 0) {
                $errors[] = "Строка {$this->rowNumber}: некорректное количество";
            }
            
            if ($this->unitPrice === null || $this->unitPrice < 0) {
                $errors[] = "Строка {$this->rowNumber}: некорректная цена";
            }
            
            if (empty($this->unit)) {
                $errors[] = "Строка {$this->rowNumber}: отсутствует единица измерения";
            }
        }
        
        return $errors;
    }
    
    public function getTotalPrice(): float
    {
        if ($this->isSection) {
            return 0.0;
        }
        
        return ($this->quantity ?? 0) * ($this->unitPrice ?? 0);
    }
}

