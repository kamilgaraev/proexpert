<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class UpdateEstimateItemRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'estimate_section_id' => ['sometimes', ...$this->nullableSectionRule()],
            'item_type' => $this->itemTypeRule('sometimes'),
            'position_number' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'work_type_id' => $this->scopedWorkTypeRule('sometimes'),
            'measurement_unit_id' => $this->scopedMeasurementUnitRule('sometimes'),
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'labor_hours' => ['sometimes', 'numeric', 'min:0'],
            'machinery_hours' => ['sometimes', 'numeric', 'min:0'],
            'materials_cost' => ['sometimes', 'numeric', 'min:0'],
            'machinery_cost' => ['sometimes', 'numeric', 'min:0'],
            'labor_cost' => ['sometimes', 'numeric', 'min:0'],
            'equipment_cost' => ['sometimes', 'numeric', 'min:0'],
            'normative_rate_id' => ['sometimes', 'nullable', 'integer', 'exists:normative_rates,id'],
            'overhead_amount' => ['sometimes', 'numeric', 'min:0'],
            'profit_amount' => ['sometimes', 'numeric', 'min:0'],
            'justification' => ['nullable', 'string'],
            'is_manual' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
