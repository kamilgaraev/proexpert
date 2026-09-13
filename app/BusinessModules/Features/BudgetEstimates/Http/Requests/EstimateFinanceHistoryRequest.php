<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EstimateFinanceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['after_id' => ['sometimes', 'integer', 'min:0'], 'kind' => ['sometimes', Rule::in(['conditions', 'execution'])]];
    }
}
