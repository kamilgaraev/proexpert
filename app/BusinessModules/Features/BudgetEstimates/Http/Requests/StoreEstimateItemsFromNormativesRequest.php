<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstimateItemsFromNormativesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.estimate_norm_id' => ['required', 'integer', 'exists:estimate_norms,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.000001'],
            'items.*.estimate_section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
            'items.*.position_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
