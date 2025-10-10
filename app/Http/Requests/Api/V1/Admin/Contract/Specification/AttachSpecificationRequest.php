<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\Specification;

use Illuminate\Foundation\Http\FormRequest;

class AttachSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specification_id' => 'required|integer|exists:specifications,id',
        ];
    }

    public function messages(): array
    {
        return [
            'specification_id.required' => 'ID спецификации обязателен',
            'specification_id.integer' => 'ID спецификации должен быть числом',
            'specification_id.exists' => 'Спецификация не найдена',
        ];
    }
}

