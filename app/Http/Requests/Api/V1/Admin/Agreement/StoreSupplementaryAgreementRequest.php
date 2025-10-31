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
        $contractId = $this->input('contract_id');

        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'number' => ['required', 'string', 'max:255'],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'change_amount' => ['nullable', 'numeric', 'required_without:new_amount'],
            'new_amount' => ['nullable', 'numeric', 'min:0', 'required_without:change_amount'],
            'supersede_agreement_ids' => [
                'nullable',
                'array',
            ],
            'supersede_agreement_ids.*' => [
                'required',
                'integer',
                'exists:supplementary_agreements,id',
                function ($attribute, $value, $fail) use ($contractId) {
                    // Проверяем, что ДС принадлежит тому же контракту
                    $agreement = \App\Models\SupplementaryAgreement::find($value);
                    if ($agreement && $contractId && $agreement->contract_id != $contractId) {
                        $fail("Дополнительное соглашение #{$value} не принадлежит указанному контракту.");
                    }
                },
            ],
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
            'supersede_agreement_ids.*.exists' => 'Одно из указанных дополнительных соглашений не найдено',
        ];
    }

    public function toDto(): SupplementaryAgreementDTO
    {
        $supersedeIds = $this->validated('supersede_agreement_ids');
        if ($supersedeIds !== null) {
            $supersedeIds = array_map('intval', $supersedeIds);
        }

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
            supersede_agreement_ids: $supersedeIds,
        );
    }
} 