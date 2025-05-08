<?php

namespace App\Http\Requests\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\AdvanceAccountTransaction;

class TransactionApprovalRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для этого запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        $transaction = $this->route('transaction');
        return $this->user()->can('approve', $transaction);
    }

    /**
     * Получить правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $transaction = $this->route('transaction');
        
        // Утвердить можно только отчитанные транзакции
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_REPORTED) {
            return [];
        }
        
        return [
            'comment' => 'nullable|string|max:1000',
            'accounting_data' => 'nullable|array',
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
            'comment.max' => 'Комментарий не должен превышать 1000 символов',
        ];
    }

    /**
     * Подготовить данные для валидации.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // Проверяем статус транзакции
        $transaction = $this->route('transaction');
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_REPORTED) {
            $this->merge(['_skip_validation' => true]);
        }
    }
} 