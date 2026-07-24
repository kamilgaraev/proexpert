<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

class MoveEstimateItemRequest extends EstimateItemRequest
{
    public function rules(): array
    {
        return [
            'section_id' => $this->requiredSectionRule(),
        ];
    }
}
