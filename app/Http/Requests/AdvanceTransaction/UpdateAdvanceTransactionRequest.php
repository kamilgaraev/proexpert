<?php

namespace App\Http\Requests\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\AdvanceAccountTransaction;

class UpdateAdvanceTransactionRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для этого запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        // Проверка прав доступа пользователя
        $transaction = $this->route('transaction');
        return $this->user()->can('update', $transaction);
    }

    /**
     * Получить правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        // Разрешаем обновлять только определенные поля, 
        // если транзакция еще не отчитана или не утверждена
        $transaction = $this->route('transaction');
        
        if ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_APPROVED) {
            // Для утвержденных транзакций ограничиваем изменения только внешними данными
            return [
                'external_code' => 'nullable|string|max:100',
                'accounting_data' => 'nullable|array',
            ];
        } elseif ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_REPORTED) {
            // Для отчитанных, но не утвержденных транзакций
            return [
                'description' => 'nullable|string|max:255',
                'document_number' => 'nullable|string|max:100',
                'document_date' => 'nullable|date',
                'external_code' => 'nullable|string|max:100',
                'accounting_data' => 'nullable|array',
                'attachment_ids' => 'nullable|string',
            ];
        } else {
            // Для транзакций в статусе "ожидание отчета"
            return [
                'project_id' => 'nullable|exists:projects,id',
                'description' => 'nullable|string|max:255',
                'document_number' => 'nullable|string|max:100',
                'document_date' => 'nullable|date',
                'external_code' => 'nullable|string|max:100',
                'accounting_data' => 'nullable|array',
                'attachment_ids' => 'nullable|string',
            ];
        }
    }

    /**
     * Получить сообщения об ошибках для определенных правил валидации.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'project_id.exists' => 'Указанный проект не найден',
            'document_date.date' => 'Некорректный формат даты',
        ];
    }
} 