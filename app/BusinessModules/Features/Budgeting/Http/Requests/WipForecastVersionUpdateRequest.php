<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use Illuminate\Validation\Rule;

final class WipForecastVersionUpdateRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'group_by' => ['sometimes', 'nullable'],
            'group_by.*' => ['sometimes', 'nullable', 'string', Rule::in(WipForecastReportFilters::ALLOWED_GROUP_BY)],
        ];
    }
}
