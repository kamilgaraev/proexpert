<?php

namespace App\Http\Requests\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\AdvanceAccountTransaction;

class CreateAdvanceTransactionRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для этого запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        // Проверка прав доступа пользователя
        return $this->user()->can('create', AdvanceAccountTransaction::class);
    }

    /**
     * Получить правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'user_id' => 'required|exists:users,id',
            'organization_id' => 'required|exists:organizations,id',
            'project_id' => 'nullable|exists:projects,id',
            'type' => 'required|in:issue,expense,return',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'external_code' => 'nullable|string|max:100',
            'accounting_data' => 'nullable|array',
            'attachment_ids' => 'nullable|string',
        ];
    }

    /**
     * Получить сообщения об ошибках для определенных правил валидации.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'user_id.required' => 'Необходимо указать пользователя (прораба)',
            'user_id.exists' => 'Указанный пользователь не найден',
            'organization_id.required' => 'Необходимо указать организацию',
            'organization_id.exists' => 'Указанная организация не найдена',
            'project_id.exists' => 'Указанный проект не найден',
            'type.required' => 'Необходимо указать тип транзакции',
            'type.in' => 'Недопустимый тип транзакции. Допустимые значения: issue, expense, return',
            'amount.required' => 'Необходимо указать сумму',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Сумма должна быть больше нуля',
            'document_date.date' => 'Некорректный формат даты',
        ];
    }
} 