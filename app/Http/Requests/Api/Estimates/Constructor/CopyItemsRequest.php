<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class CopyItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'exists:estimate_items,id'],
            'target_estimate_id' => ['required', 'integer', 'exists:estimates,id'],
            'target_section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
        ];
    }
}
