<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdvanceTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Проверка прав доступа пользователя (например, может ли создавать транзакции)
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
            'user_id' => 'required|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'type' => 'required|in:issue,expense,return',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
        ];
    }
} 