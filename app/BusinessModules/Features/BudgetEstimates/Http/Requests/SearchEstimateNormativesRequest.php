<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchEstimateNormativesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:255'],
            'norm_type' => ['nullable', 'string', 'max:50'],
            'collection_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'has_prices' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
