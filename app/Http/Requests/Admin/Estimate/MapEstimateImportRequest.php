<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class MapEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('estimates.create');
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string'],
            'column_mapping' => ['required', 'array'],
            'column_mapping.*' => [
                'required',
                'string',
            ],
        ];
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

