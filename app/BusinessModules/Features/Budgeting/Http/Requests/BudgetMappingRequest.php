<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetMappingRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'budget_article_id' => ['required', 'string', 'max:36'],
            'system' => ['sometimes', 'string', Rule::in(['1c'])],
            'one_c_base_id' => ['required', 'integer'],
            'integration_profile_id' => ['sometimes', 'nullable', 'integer'],
            'external_code' => ['required', 'string', 'max:128'],
            'external_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mapping_payload' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
