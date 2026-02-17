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
        
        // Дополнительные поля
        public ?float $quantityCoefficient = null,
        public ?float $quantityTotal = null,
        public ?float $baseUnitPrice = null,
        public ?float $priceIndex = null,
        public ?float $currentUnitPrice = null,
        public ?float $priceCoefficient = null,
        public ?float $currentTotalAmount = null, // Сумма из файла
        public ?float $overheadAmount = null,
        public ?float $profitAmount = null,
        public bool $isManual = false,
        public bool $isNotAccounted = false,
        
        // Поля валидации и классификации
        public ?float $confidenceScore = null,
        public ?string $classificationSource = null,
        public array $warnings = [],
        public bool $hasMathMismatch = false,
        
        // Вложенные данные из XML
        public ?array $worksList = null,
        public ?array $totals = null,
        public bool $isFooter = false
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
            'overhead_amount' => $this->overheadAmount,
            'profit_amount' => $this->profitAmount,
            'is_manual' => $this->isManual,
            'is_not_accounted' => $this->isNotAccounted,
            'confidence_score' => $this->confidenceScore,
            'classification_source' => $this->classificationSource,
            'warnings' => $this->warnings,
            'has_math_mismatch' => $this->hasMathMismatch,
            'works_list' => $this->worksList,
            'totals' => $this->totals,
            'is_footer' => $this->isFooter,
        ];
    }
    
    public function validate(): array
    {
        // ... (validation logic can be moved to external validator, but keeping basic checks here is fine)
        return $this->warnings;
    }
    
    public function getTotalPrice(): float
    {
        if ($this->isSection) {
            return 0.0;
        }
        
        return ($this->quantity ?? 0) * ($this->unitPrice ?? 0);
    }
    
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
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
            overheadAmount: $data['overhead_amount'] ?? $rawData['overhead_amount'] ?? null,
            profitAmount: $data['profit_amount'] ?? $rawData['profit_amount'] ?? null,
            isManual: $data['is_manual'] ?? $rawData['is_manual'] ?? false,
            isNotAccounted: $data['is_not_accounted'] ?? $rawData['is_not_accounted'] ?? false,
            confidenceScore: $data['confidence_score'] ?? null,
            classificationSource: $data['classification_source'] ?? null,
            warnings: $data['warnings'] ?? [],
            hasMathMismatch: $data['has_math_mismatch'] ?? false,
            worksList: $data['works_list'] ?? ($data['raw_data']['works_list'] ?? null),
            totals: $data['totals'] ?? ($data['raw_data']['totals'] ?? null),
            isFooter: $data['is_footer'] ?? false
        );
    }
}
