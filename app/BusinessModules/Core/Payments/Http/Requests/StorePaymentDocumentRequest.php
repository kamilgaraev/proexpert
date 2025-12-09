<?php

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Авторизация проверяется в middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Для авансов, привязанных к контракту, сумма может быть опциональной (будет рассчитана автоматически)
        $isAdvanceWithContract = $this->input('invoice_type') === 'advance' 
            && (
                ($this->input('source_type') === 'App\\Models\\Contract' && $this->input('source_id'))
                || ($this->input('invoiceable_type') === 'App\\Models\\Contract' && $this->input('invoiceable_id'))
                || $this->input('contract_id')
            );

        return [
            'document_type' => 'required|string|in:payment_request,invoice,payment_order,incoming_payment,expense,offset_act',
            'document_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'project_id' => 'nullable|integer|exists:projects,id',
            'payer_organization_id' => 'nullable|integer|exists:organizations,id',
            'payer_contractor_id' => 'nullable|integer|exists:contractors,id',
            'payee_organization_id' => 'nullable|integer|exists:organizations,id',
            'payee_contractor_id' => 'nullable|integer|exists:contractors,id',
            'amount' => $isAdvanceWithContract ? 'nullable|numeric|min:0' : 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'invoiceable_type' => 'nullable|string',
            'invoiceable_id' => 'nullable|integer',
            'contract_id' => 'nullable|integer|exists:contracts,id',
            'invoice_type' => 'nullable|string|in:act,advance,progress,final,material_purchase,service,equipment,salary,other',
            'description' => 'nullable|string',
            'payment_purpose' => 'nullable|string',
            'bank_account' => 'nullable|string|size:20',
            'bank_bik' => 'nullable|string|size:9',
            'bank_correspondent_account' => 'nullable|string|size:20',
            'bank_name' => 'nullable|string',
            'attached_documents' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Тип документа обязателен',
            'document_type.in' => 'Недопустимый тип документа',
            'amount.required' => 'Сумма обязательна',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Сумма должна быть не менее 0.01',
            'vat_rate.min' => 'Ставка НДС не может быть отрицательной',
            'vat_rate.max' => 'Ставка НДС не может превышать 100%',
            'currency.size' => 'Код валюты должен состоять из 3 символов',
            'bank_account.size' => 'Банковский счет должен состоять из 20 символов',
            'bank_bik.size' => 'БИК должен состоять из 9 символов',
            'bank_correspondent_account.size' => 'Корреспондентский счет должен состоять из 20 символов',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Нормализуем числовые поля перед валидацией
        if ($this->has('amount')) {
            $this->merge([
                'amount' => $this->convertToNumber($this->amount),
            ]);
        }

        if ($this->has('vat_rate')) {
            $this->merge([
                'vat_rate' => $this->convertToNumber($this->vat_rate),
            ]);
        }
    }

    /**
     * Преобразовать строку в число
     * Поддерживает форматы: "123.45", "123,45", "123 456.78", "123 456,78"
     */
    private function convertToNumber($value)
    {
        // Если уже число, возвращаем как есть
        if (is_numeric($value)) {
            return $value;
        }

        // Если не строка, возвращаем как есть
        if (!is_string($value)) {
            return $value;
        }

        // Убираем пробелы
        $value = str_replace(' ', '', $value);

        // Если содержит и точку, и запятую - определяем разделитель по позиции
        if (strpos($value, '.') !== false && strpos($value, ',') !== false) {
            $lastDot = strrpos($value, '.');
            $lastComma = strrpos($value, ',');
            
            // Последний символ определяет десятичный разделитель
            if ($lastDot > $lastComma) {
                // Точка - десятичный разделитель, запятая - разделитель тысяч
                $value = str_replace(',', '', $value);
            } else {
                // Запятая - десятичный разделитель, точка - разделитель тысяч
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } elseif (strpos($value, ',') !== false) {
            // Только запятая - заменяем на точку
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : $value;
    }
}

