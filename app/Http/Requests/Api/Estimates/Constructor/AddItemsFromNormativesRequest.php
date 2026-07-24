<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class AddItemsFromNormativesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.normative_rate_id' => ['required', 'integer', 'exists:normative_rates,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
            'apply_indices' => ['sometimes', 'boolean'],
            'calculation_date' => ['nullable', 'date'],
            'coefficients' => ['nullable', 'array'],
            'coefficients.*.id' => ['required_with:coefficients', 'integer', 'exists:regional_coefficients,id'],
        ];
    }
}
