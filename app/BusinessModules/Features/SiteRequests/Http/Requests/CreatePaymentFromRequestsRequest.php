<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentFromRequestsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Проверка авторизации через middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'request_ids' => 'required|array|min:1',
            'request_ids.*' => 'required|integer|exists:site_requests,id',
            'payee_contractor_id' => 'required|integer|exists:contractors,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'vat_rate' => 'sometimes|numeric|min:0|max:100',
            'description' => 'sometimes|string|max:1000',
            'payment_purpose' => 'sometimes|string|max:500',
            'due_date' => 'sometimes|date|after_or_equal:today',
            'payment_terms_days' => 'sometimes|integer|min:1|max:365',
            'document_date' => 'sometimes|date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'request_ids.required' => 'Необходимо выбрать хотя бы одну заявку',
            'request_ids.array' => 'Заявки должны быть переданы в виде массива',
            'request_ids.min' => 'Необходимо выбрать хотя бы одну заявку',
            'request_ids.*.exists' => 'Одна или несколько выбранных заявок не найдены',
            'payee_contractor_id.required' => 'Необходимо указать подрядчика-получателя',
            'payee_contractor_id.exists' => 'Выбранный подрядчик не найден',
            'amount.required' => 'Необходимо указать сумму платежа',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Сумма должна быть больше нуля',
            'due_date.after_or_equal' => 'Срок оплаты не может быть в прошлом',
        ];
    }
}

