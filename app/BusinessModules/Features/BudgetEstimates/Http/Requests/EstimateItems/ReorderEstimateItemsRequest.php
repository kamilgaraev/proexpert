<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

use Illuminate\Validation\Rule;

class ReorderEstimateItemsRequest extends EstimateItemRequest
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
            'items.*.estimate_section_id' => $this->nullableSectionRule(),
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
            'numbering_mode' => ['nullable', 'string', Rule::in(['global', 'section', 'hierarchical'])],
        ];
    }
}
