<?php

namespace App\Http\Requests\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\AdvanceAccountTransaction;

class TransactionReportRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для этого запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        $transaction = $this->route('transaction');
        return $this->user()->can('report', $transaction);
    }

    /**
     * Получить правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $transaction = $this->route('transaction');
        
        // Отчет можно подать только для транзакций в статусе ожидания
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
            return [];
        }
        
        return [
            'description' => 'required|string|max:255',
            'document_number' => 'required|string|max:100',
            'document_date' => 'required|date',
            'files' => 'sometimes|array',
            'files.*' => 'file|max:10240',
            'comment' => 'nullable|string|max:1000',
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
            'description.required' => 'Необходимо указать описание расхода',
            'document_number.required' => 'Необходимо указать номер документа',
            'document_date.required' => 'Необходимо указать дату документа',
            'document_date.date' => 'Некорректный формат даты',
            'files.*.file' => 'Загруженный файл недействителен',
            'files.*.max' => 'Размер файла не должен превышать 10 МБ',
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
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
            $this->merge(['_skip_validation' => true]);
        }
    }
} 