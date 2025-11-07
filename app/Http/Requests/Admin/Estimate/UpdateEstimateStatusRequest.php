<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstimateStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller via Policy
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(['draft', 'in_review', 'approved', 'cancelled']),
            ],
            'comment' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Статус обязателен для заполнения',
            'status.in' => 'Недопустимое значение статуса. Разрешены: draft, in_review, approved, cancelled',
            'comment.max' => 'Комментарий не может превышать 1000 символов',
        ];
    }
}

