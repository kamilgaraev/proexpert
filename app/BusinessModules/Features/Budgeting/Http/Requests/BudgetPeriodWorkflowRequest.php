<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetPeriodWorkflowRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'closure_mode' => ['sometimes', 'nullable', 'string', Rule::in(['soft', 'hard'])],
            'reason' => ['required', 'string', 'max:1000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
