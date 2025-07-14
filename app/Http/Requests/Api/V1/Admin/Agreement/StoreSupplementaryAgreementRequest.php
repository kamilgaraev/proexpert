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
            'change_amount' => ['required', 'numeric'],
            'subject_changes' => ['required', 'array'],
            'subject_changes.*' => ['string'],
        ];
    }

    public function toDto(): SupplementaryAgreementDTO
    {
        return new SupplementaryAgreementDTO(
            contract_id: $this->validated('contract_id'),
            number: $this->validated('number'),
            agreement_date: $this->validated('agreement_date'),
            change_amount: (float) $this->validated('change_amount'),
            subject_changes: $this->validated('subject_changes'),
        );
    }
} 