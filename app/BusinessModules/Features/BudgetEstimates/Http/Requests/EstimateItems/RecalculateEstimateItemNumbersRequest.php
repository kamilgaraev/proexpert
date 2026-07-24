<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

use Illuminate\Validation\Rule;

class RecalculateEstimateItemNumbersRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'numbering_mode' => ['nullable', 'string', Rule::in(['global', 'section', 'hierarchical'])],
        ];
    }
}
