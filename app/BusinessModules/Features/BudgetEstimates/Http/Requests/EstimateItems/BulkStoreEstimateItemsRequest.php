<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class BulkStoreEstimateItemsRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:' . self::MAX_BULK_ITEMS],
            'items.*.estimate_section_id' => $this->nullableSectionRule(),
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.work_type_id' => $this->scopedWorkTypeRule(),
            'items.*.measurement_unit_id' => $this->scopedMeasurementUnitRule(),
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.overhead_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.profit_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.justification' => ['nullable', 'string'],
            'items.*.is_manual' => ['nullable', 'boolean'],
        ];
    }
}
