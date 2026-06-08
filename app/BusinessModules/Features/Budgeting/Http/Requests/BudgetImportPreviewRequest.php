<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetImportPreviewRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            'template_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'mapping_mode' => ['sometimes', 'string', Rule::in(['by_code', 'by_name'])],
        ];
    }
}
