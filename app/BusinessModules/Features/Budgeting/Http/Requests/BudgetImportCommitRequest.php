<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetImportCommitRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'import_batch_id' => ['required', 'string', 'max:36'],
            'mode' => ['required', 'string', Rule::in(['replace_lines', 'append_lines'])],
        ];
    }
}
