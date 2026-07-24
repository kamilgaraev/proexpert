<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class StoreEstimateItemRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'estimate_section_id' => $this->nullableSectionRule(),
            'item_type' => $this->itemTypeRule(),
            'position_number' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'work_type_id' => $this->scopedWorkTypeRule(),
            'measurement_unit_id' => $this->scopedMeasurementUnitRule(),
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'labor_hours' => ['nullable', 'numeric', 'min:0'],
            'machinery_hours' => ['nullable', 'numeric', 'min:0'],
            'materials_cost' => ['nullable', 'numeric', 'min:0'],
            'machinery_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'equipment_cost' => ['nullable', 'numeric', 'min:0'],
            'normative_rate_id' => ['nullable', 'integer', 'exists:normative_rates,id'],
            'overhead_amount' => ['nullable', 'numeric', 'min:0'],
            'profit_amount' => ['nullable', 'numeric', 'min:0'],
            'justification' => ['nullable', 'string'],
            'is_manual' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
