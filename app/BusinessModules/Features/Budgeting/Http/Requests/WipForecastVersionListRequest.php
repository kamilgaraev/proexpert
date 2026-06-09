<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class WipForecastVersionListRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_start'],
            'budget_version_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scenario_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ];
    }
}
