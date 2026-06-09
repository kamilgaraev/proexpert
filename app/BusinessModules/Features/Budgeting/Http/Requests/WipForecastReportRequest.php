<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use Illuminate\Validation\Rule;

class WipForecastReportRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return $this->wipForecastRules();
    }

    protected function wipForecastRules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
            'forecast_version_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'budget_version_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scenario_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'stage_id' => ['sometimes', 'nullable', 'integer'],
            'contract_id' => ['sometimes', 'nullable', 'integer'],
            'estimate_item_id' => ['sometimes', 'nullable', 'integer'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'group_by' => ['sometimes', 'nullable'],
            'group_by.*' => ['sometimes', 'nullable', 'string', Rule::in(WipForecastReportFilters::ALLOWED_GROUP_BY)],
        ];
    }
}
