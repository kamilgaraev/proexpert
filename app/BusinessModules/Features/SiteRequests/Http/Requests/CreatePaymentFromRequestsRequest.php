<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentFromRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->getCurrentOrganizationId();

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.create', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        $organizationId = $this->getCurrentOrganizationId();

        return [
            'request_ids' => 'required|array|min:1',
            'request_ids.*' => [
                'required',
                'integer',
                Rule::exists('site_requests', 'id')->where('organization_id', $organizationId),
            ],
            'payee_contractor_id' => [
                'required',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
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

    private function getCurrentOrganizationId(): int
    {
        return (int) $this->attributes->get('current_organization_id', 0);
    }
}
