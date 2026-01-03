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
        public ?array $rawData = null,
        
        // Дополнительные поля, ранее бывшие в raw_data
        public ?float $quantityCoefficient = null,
        public ?float $quantityTotal = null,
        public ?float $baseUnitPrice = null,
        public ?float $priceIndex = null,
        public ?float $currentUnitPrice = null,
        public ?float $priceCoefficient = null,
        public ?float $currentTotalAmount = null,
        public bool $isNotAccounted = false
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
            'quantity_coefficient' => $this->quantityCoefficient,
            'quantity_total' => $this->quantityTotal,
            'base_unit_price' => $this->baseUnitPrice,
            'price_index' => $this->priceIndex,
            'current_unit_price' => $this->currentUnitPrice,
            'price_coefficient' => $this->priceCoefficient,
            'current_total_amount' => $this->currentTotalAmount,
            'is_not_accounted' => $this->isNotAccounted,
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
                // Если количество 0, это может быть информационная строка, но обычно это ошибка
                // Оставим проверку, но сделаем её мягче, если это некритично для системы
                $errors[] = "Строка {$this->rowNumber}: некорректное количество";
            }
            
            // Цену проверяем менее строго, так как она может быть 0
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

    public static function fromArray(array $data): self
    {
        $rawData = $data['raw_data'] ?? [];
        
        return new self(
            rowNumber: $data['row_number'],
            sectionNumber: $data['section_number'] ?? null,
            itemName: $data['item_name'],
            unit: $data['unit'] ?? null,
            quantity: $data['quantity'] ?? null,
            unitPrice: $data['unit_price'] ?? null,
            code: $data['code'] ?? null,
            isSection: $data['is_section'] ?? false,
            itemType: $data['item_type'] ?? 'work',
            level: $data['level'] ?? 0,
            sectionPath: $data['section_path'] ?? null,
            rawData: $rawData,
            quantityCoefficient: $data['quantity_coefficient'] ?? $rawData['quantity_coefficient'] ?? null,
            quantityTotal: $data['quantity_total'] ?? $rawData['quantity_total'] ?? null,
            baseUnitPrice: $data['base_unit_price'] ?? $rawData['base_unit_price'] ?? null,
            priceIndex: $data['price_index'] ?? $rawData['price_index'] ?? null,
            currentUnitPrice: $data['current_unit_price'] ?? $rawData['current_unit_price'] ?? null,
            priceCoefficient: $data['price_coefficient'] ?? $rawData['price_coefficient'] ?? null,
            currentTotalAmount: $data['current_total_amount'] ?? $rawData['current_total_amount'] ?? null,
            isNotAccounted: $data['is_not_accounted'] ?? $rawData['is_not_accounted'] ?? false
        );
    }
}
