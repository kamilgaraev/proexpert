<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdvanceTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Проверка прав доступа пользователя (например, может ли редактировать эту транзакцию)
        return true; // Заменить на реальную проверку прав
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Правила валидации для обновления - обычно обновляются только некоторые поля
        return [
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'external_code' => 'nullable|string|max:100',
            'accounting_data' => 'nullable|array',
        ];
    }
} 