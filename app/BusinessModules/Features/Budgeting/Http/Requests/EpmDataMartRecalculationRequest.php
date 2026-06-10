<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use Illuminate\Validation\Rule;

final class EpmDataMartRecalculationRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'nullable', 'integer'],
            'current_organization_id' => ['sometimes', 'nullable', 'integer'],
            'report_scope' => ['sometimes', 'nullable', 'string', Rule::in([...EpmDataMartScope::SUPPORTED_REPORT_SCOPES, 'all'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'project_manager_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'project_status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cost_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'responsibility_center_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'budget_article_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'counterparty_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'budget_version_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scenario_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'item_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
