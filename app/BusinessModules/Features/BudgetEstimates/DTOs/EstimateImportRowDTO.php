<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class EstimateImportRowDTO
{
    public array $subItems = [];

    public function __construct(
        public int $rowNumber = 0,
        public ?string $sectionNumber = null,
        public string $itemName = '',
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $unitPrice = null,
        public ?string $code = null,
        public bool $isSection = false,
        public bool $isSubItem = false,
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
        public ?float $overheadRate = null,
        public ?float $profitRate = null,
        
        // Detailed Costs
        public ?float $baseLaborCost = null,
        public ?float $baseMachineryCost = null,
        public ?float $baseMachineryLaborCost = null,
        public ?float $baseMaterialsCost = null,
        public ?float $laborCost = null,
        public ?float $machineryCost = null,
        public ?float $materialsCost = null,
        
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
            'total_amount' => $this->currentTotalAmount,
            'code' => $this->code,
            'is_section' => $this->isSection,
            'is_sub_item' => $this->isSubItem,
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
            'overhead_rate' => $this->overheadRate,
            'profit_rate' => $this->profitRate,
            'base_labor_cost' => $this->baseLaborCost,
            'base_machinery_cost' => $this->baseMachineryCost,
            'base_machinery_labor_cost' => $this->baseMachineryLaborCost,
            'base_materials_cost' => $this->baseMaterialsCost,
            'labor_cost' => $this->laborCost,
            'machinery_cost' => $this->machineryCost,
            'materials_cost' => $this->materialsCost,
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
            isSubItem: $data['is_sub_item'] ?? false,
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
            overheadRate: $data['overhead_rate'] ?? $rawData['overhead_rate'] ?? null,
            profitRate: $data['profit_rate'] ?? $rawData['profit_rate'] ?? null,
            baseLaborCost: $data['base_labor_cost'] ?? $rawData['base_labor_cost'] ?? null,
            baseMachineryCost: $data['base_machinery_cost'] ?? $rawData['base_machinery_cost'] ?? null,
            baseMachineryLaborCost: $data['base_machinery_labor_cost'] ?? $rawData['base_machinery_labor_cost'] ?? null,
            baseMaterialsCost: $data['base_materials_cost'] ?? $rawData['base_materials_cost'] ?? null,
            laborCost: $data['labor_cost'] ?? $rawData['labor_cost'] ?? null,
            machineryCost: $data['machinery_cost'] ?? $rawData['machinery_cost'] ?? null,
            materialsCost: $data['materials_cost'] ?? $rawData['materials_cost'] ?? null,
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
