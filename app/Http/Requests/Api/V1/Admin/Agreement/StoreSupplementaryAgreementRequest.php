<?php

namespace App\Http\Requests\Api\V1\Admin\Agreement;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\SupplementaryAgreementDTO;

class StoreSupplementaryAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'number' => ['required', 'string', 'max:255'],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'change_amount' => ['nullable', 'numeric', 'required_without:new_amount'],
            'new_amount' => ['nullable', 'numeric', 'min:0', 'required_without:change_amount'],
            'subject_changes' => ['required', 'array'],
            'subject_changes.*' => ['string'],
            'subcontract_changes' => ['nullable', 'array'],
            'gp_changes' => ['nullable', 'array'],
            'advance_changes' => ['nullable', 'array'],
            'advance_changes.*.payment_id' => ['required', 'integer', 'exists:contract_payments,id'],
            'advance_changes.*.new_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'change_amount.required_without' => 'Необходимо указать либо изменение суммы (change_amount), либо новую сумму (new_amount)',
            'new_amount.required_without' => 'Необходимо указать либо изменение суммы (change_amount), либо новую сумму (new_amount)',
            'new_amount.min' => 'Новая сумма не может быть отрицательной',
        ];
    }

    public function toDto(): SupplementaryAgreementDTO
    {
        return new SupplementaryAgreementDTO(
            contract_id: $this->validated('contract_id'),
            number: $this->validated('number'),
            agreement_date: $this->validated('agreement_date'),
            change_amount: $this->validated('change_amount') !== null ? (float) $this->validated('change_amount') : null,
            subject_changes: $this->validated('subject_changes'),
            subcontract_changes: $this->validated('subcontract_changes'),
            gp_changes: $this->validated('gp_changes'),
            advance_changes: $this->validated('advance_changes'),
            new_amount: $this->validated('new_amount') !== null ? (float) $this->validated('new_amount') : null,
        );
    }
} 