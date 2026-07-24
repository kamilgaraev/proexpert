<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

use Illuminate\Validation\Rule;

class BulkUpdateEstimateItemsRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:' . self::MAX_BULK_ITEMS],
            'items.*.id' => [
                'required',
                'integer',
                Rule::exists('estimate_items', 'id')->where('estimate_id', $this->estimateId()),
            ],
            'items.*.estimate_section_id' => ['sometimes', ...$this->nullableSectionRule()],
            'items.*.section_id' => ['sometimes', ...$this->nullableSectionRule()],
            'items.*.item_type' => $this->itemTypeRule('sometimes'),
            'items.*.position_number' => ['sometimes', 'string', 'max:50'],
            'items.*.name' => ['sometimes', 'string', 'max:255'],
            'items.*.description' => ['sometimes', 'nullable', 'string'],
            'items.*.work_type_id' => $this->scopedWorkTypeRule('sometimes'),
            'items.*.measurement_unit_id' => $this->scopedMeasurementUnitRule('sometimes'),
            'items.*.quantity' => ['sometimes', 'numeric', 'min:0'],
            'items.*.quantity_coefficient' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.quantity_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'items.*.base_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.price_index' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.current_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.price_coefficient' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.current_total_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.labor_hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.machinery_hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.materials_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.machinery_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.labor_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.equipment_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.direct_costs' => ['sometimes', 'numeric', 'min:0'],
            'items.*.overhead_amount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.profit_amount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.justification' => ['sometimes', 'nullable', 'string'],
            'items.*.is_manual' => ['sometimes', 'boolean'],
            'items.*.metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
