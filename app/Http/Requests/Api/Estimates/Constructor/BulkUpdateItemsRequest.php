<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:estimate_items,id'],
            'items.*.expected_updated_at' => ['sometimes', 'required', 'date'],
            'items.*.quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.direct_costs' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.overhead_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.profit_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'items.*.section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
            'items.*.position_number' => ['nullable', 'string'],
        ];
    }
}
