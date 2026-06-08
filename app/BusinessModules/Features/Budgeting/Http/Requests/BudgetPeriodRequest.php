<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetPeriodRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', 'string', Rule::in(['month', 'quarter', 'year', 'project'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'soft_closed', 'closed', 'reopened_for_adjustment', 'archived'])],
        ];
    }
}
