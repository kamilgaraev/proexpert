<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class UploadEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,xml,csv,txt',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Необходимо выбрать файл для загрузки',
            'file.mimes' => 'Поддерживаемые форматы: Excel (.xlsx, .xls), XML (.xml), CSV (.csv), текст (.txt)',
            'file.max' => 'Размер файла не должен превышать 10 МБ',
        ];
    }
}

