<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetArticleRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'code' => ['required', 'string', 'max:96'],
            'name' => ['required', 'string', 'max:255'],
            'budget_kind' => ['required', 'string', Rule::in(['bdr', 'bdds', 'both', 'technical'])],
            'flow_direction' => ['required', 'string', Rule::in(['income', 'expense', 'inflow', 'outflow', 'neutral'])],
            'is_leaf' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'cost_category_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
