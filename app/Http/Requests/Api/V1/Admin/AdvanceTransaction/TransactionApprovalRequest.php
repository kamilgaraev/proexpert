<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;

class TransactionApprovalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Проверка прав доступа пользователя (например, может ли утверждать отчеты)
        return true; // Заменить на реальную проверку прав
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accounting_data' => 'nullable|array',
        ];
    }
} 