<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class IndexEstimateItemsRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'max:1000'],
        ];
    }
}
