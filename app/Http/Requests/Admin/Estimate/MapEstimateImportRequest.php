<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class MapEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Allow both string and array (will be normalized in controller)
            'file_id' => ['required'],
            'column_mapping' => ['required', 'array'],
            'column_mapping.*' => [
                'required',
                'string',
            ],
        ];
    }
    
    protected function prepareForValidation(): void
    {
        // Normalize file_id if it's an array
        $fileId = $this->input('file_id');
        if (is_array($fileId)) {
            $this->merge([
                'file_id' => $fileId[0] ?? '',
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'file_id.required' => 'Не указан идентификатор файла',
            'column_mapping.required' => 'Необходимо указать маппинг колонок',
            'column_mapping.array' => 'Маппинг колонок должен быть массивом',
        ];
    }
}

