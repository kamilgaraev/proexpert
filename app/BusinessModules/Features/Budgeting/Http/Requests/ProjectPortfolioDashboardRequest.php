<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class ProjectPortfolioDashboardRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
            'project_manager_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'project_status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'completed', 'paused', 'cancelled', 'draft'])],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'responsibility_center_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'top_n' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
