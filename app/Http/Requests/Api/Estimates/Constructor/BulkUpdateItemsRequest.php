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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:estimate_items,id'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.unit_price' => ['nullable', 'numeric'],
            'items.*.direct_costs' => ['nullable', 'numeric'],
            'items.*.overhead_amount' => ['nullable', 'numeric'],
            'items.*.profit_amount' => ['nullable', 'numeric'],
            'items.*.total_amount' => ['nullable', 'numeric'],
            'items.*.section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
            'items.*.position_number' => ['nullable', 'string'],
        ];
    }
}
