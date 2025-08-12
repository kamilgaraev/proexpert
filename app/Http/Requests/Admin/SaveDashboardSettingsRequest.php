<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveDashboardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'layout_mode' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'max:50'],
            'items.*.id' => ['required', 'string', 'max:100'],
            'items.*.enabled' => ['required', 'boolean'],
            'items.*.order' => ['required', 'integer', 'min:0'],
            'items.*.size' => ['nullable', 'array'],
            'items.*.size.xs' => ['nullable', 'integer', 'min:1'],
            'items.*.size.md' => ['nullable', 'integer', 'min:1'],
            'items.*.size.lg' => ['nullable', 'integer', 'min:1'],
            'items.*.layout' => ['nullable', 'array'],
            'items.*.layout.x' => ['nullable', 'integer', 'min:0'],
            'items.*.layout.y' => ['nullable', 'integer', 'min:0'],
            'items.*.layout.w' => ['nullable', 'integer', 'min:1'],
            'items.*.layout.h' => ['nullable', 'integer', 'min:1'],
        ];
    }
}


