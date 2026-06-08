<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetScenarioRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'scenario_type' => ['required', 'string', Rule::in(['base', 'optimistic', 'stress', 'custom'])],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
