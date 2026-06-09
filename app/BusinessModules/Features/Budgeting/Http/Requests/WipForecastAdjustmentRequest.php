<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class WipForecastAdjustmentRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'scope' => ['sometimes', 'nullable', 'string', Rule::in(['forecast', 'project', 'stage', 'contract', 'estimate_item', 'line'])],
            'scope_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'stage_id' => ['sometimes', 'nullable', 'integer'],
            'contract_id' => ['sometimes', 'nullable', 'integer'],
            'estimate_item_id' => ['sometimes', 'nullable', 'integer'],
            'period' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'adjustment_type' => ['sometimes', 'nullable', 'string', Rule::in(['cost', 'revenue', 'progress'])],
            'formula_component' => ['required', 'string', Rule::in(['ftc', 'etc', 'forecast_revenue'])],
            'amount' => ['required', 'numeric'],
            'percent' => ['sometimes', 'nullable', 'numeric'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'reason' => ['required', 'string', 'max:2000'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
