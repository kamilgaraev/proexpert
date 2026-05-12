<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

class ListFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'folder' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', 'string'],
            'filename' => ['sometimes', 'nullable', 'string'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'sort_by' => ['sometimes', 'in:created_at,size,filename'],
            'sort_dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->all() as $key => $value) {
            $clean[$key] = $value === '' ? null : $value;
        }

        $this->merge($clean);
    }
}
