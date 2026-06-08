<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

class PlanFactReportRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return $this->planFactRules();
    }

    protected function planFactRules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'budget_version_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scenario_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'responsibility_center_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'budget_article_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'counterparty_id' => ['sometimes', 'nullable', 'integer'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'group_by' => ['sometimes', 'nullable'],
            'group_by.*' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
