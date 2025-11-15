<?php

namespace App\Http\Requests\Api\V1\Admin\EstimatePosition;

use Illuminate\Foundation\Http\FormRequest;

class ImportPositionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // Max 10MB
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Файл для импорта обязателен',
            'file.mimes' => 'Файл должен быть в формате Excel (xlsx, xls) или CSV',
            'file.max' => 'Размер файла не должен превышать 10 МБ',
        ];
    }
}

