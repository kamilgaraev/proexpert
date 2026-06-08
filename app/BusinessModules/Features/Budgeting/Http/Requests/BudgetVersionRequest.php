<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetVersionRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer'],
            'budget_kind' => ['required', 'string', Rule::in(['bdr', 'bdds', 'consolidated'])],
            'budget_period_id' => ['required', 'string', 'max:36'],
            'scenario_id' => ['required', 'string', 'max:36'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
