<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class UploadEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('estimates.create');
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Необходимо выбрать файл для загрузки',
            'file.mimes' => 'Файл должен быть в формате Excel (.xlsx или .xls)',
            'file.max' => 'Размер файла не должен превышать 10 МБ',
        ];
    }
}

