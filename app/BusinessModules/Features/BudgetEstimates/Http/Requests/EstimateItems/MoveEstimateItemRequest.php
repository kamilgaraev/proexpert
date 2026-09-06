<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class MoveEstimateItemRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'section_id' => ['present', ...$this->nullableSectionRule()],
            'anchor_item_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'placement' => ['required_with:anchor_item_id', 'string', 'in:before,after'],
        ];
    }
}
