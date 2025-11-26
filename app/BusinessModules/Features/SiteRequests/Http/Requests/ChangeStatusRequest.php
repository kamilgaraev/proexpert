<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use Illuminate\Validation\Rules\Enum;

/**
 * Валидация смены статуса заявки
 */
class ChangeStatusRequest extends FormRequest
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
            'status' => ['required', new Enum(SiteRequestStatusEnum::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'status' => 'статус',
            'notes' => 'комментарий',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Укажите новый статус',
            'status.Illuminate\Validation\Rules\Enum' => 'Указан недопустимый статус',
        ];
    }
}

