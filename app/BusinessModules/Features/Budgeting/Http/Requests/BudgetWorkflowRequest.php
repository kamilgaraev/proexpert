<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class BudgetWorkflowRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
