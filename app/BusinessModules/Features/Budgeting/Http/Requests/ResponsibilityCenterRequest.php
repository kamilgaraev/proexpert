<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class ResponsibilityCenterRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'center_type' => ['required', 'string', Rule::in(['holding', 'organization', 'project', 'department', 'warehouse', 'contract', 'functional_area'])],
            'code' => ['required', 'string', 'max:96'],
            'name' => ['required', 'string', 'max:255'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'approver_user_id' => ['sometimes', 'nullable', 'integer'],
            'linked_entity_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'linked_entity_id' => ['sometimes', 'nullable', 'integer'],
            'active_from' => ['sometimes', 'nullable', 'date'],
            'active_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:active_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
