<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class DetectEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация через middleware
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required'],
            'suggested_header_row' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Нормализуем file_id если пришел как массив
        if ($this->has('file_id') && is_array($this->input('file_id'))) {
            $this->merge([
                'file_id' => (string)($this->input('file_id')[0] ?? ''),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'file_id.required' => 'File ID is required',
            'suggested_header_row.integer' => 'Suggested header row must be an integer',
            'suggested_header_row.min' => 'Suggested header row must be at least 1',
        ];
    }
}

