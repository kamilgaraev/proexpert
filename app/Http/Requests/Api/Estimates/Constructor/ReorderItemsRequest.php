<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class ReorderItemsRequest extends FormRequest
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
            'items.*.position_number' => ['required', 'string'],
            'items.*.section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
        ];
    }
}
