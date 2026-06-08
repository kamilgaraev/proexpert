<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class BudgetVersionCloneRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'source_version_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'version_name' => ['required', 'string', 'max:255'],
            'copy_lines' => ['sometimes', 'boolean'],
            'copy_forecast' => ['sometimes', 'boolean'],
        ];
    }
}
