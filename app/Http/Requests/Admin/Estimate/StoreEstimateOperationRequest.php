<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEstimateOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $reprice = $this->input('mode') !== 'adjust' || $this->boolean('reprice');

        return [
            'mode' => ['required', Rule::in(['reprice', 'consolidate', 'adjust'])],
            'name' => ['sometimes', 'string', 'max:150'],
            'source_ids' => [Rule::requiredIf($this->input('mode') !== 'adjust'), 'array', 'min:1', 'max:20'],
            'source_ids.*' => ['integer', 'min:1', 'distinct'],
            'base_id' => [Rule::requiredIf($this->input('mode') === 'adjust'), 'integer', 'min:1'],
            'addition_ids' => ['sometimes', 'array', 'max:19'], 'addition_ids.*' => ['integer', 'min:1', 'distinct'],
            'subtraction_ids' => ['sometimes', 'array', 'max:19'], 'subtraction_ids.*' => ['integer', 'min:1', 'distinct'],
            'reprice' => ['sometimes', 'boolean'],
            'subtraction_quantity_mode' => [Rule::prohibitedIf($reprice), 'sometimes', Rule::in(['positive', 'signed'])],
            'target_date' => [Rule::requiredIf($reprice), 'date_format:Y-m-d'],
            'region_code' => [Rule::requiredIf($reprice), 'string', 'max:30'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'overhead_rate' => [Rule::prohibitedIf(! $reprice), 'sometimes', 'numeric', 'min:0', 'max:1000'], 'profit_rate' => [Rule::prohibitedIf(! $reprice), 'sometimes', 'numeric', 'min:0', 'max:1000'],
            'normative_collection_id' => ['nullable', 'integer', 'min:1'], 'price_index_id' => ['nullable', 'integer', 'min:1'],
            'price_list_ids' => ['sometimes', 'array', 'max:10'], 'price_list_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
