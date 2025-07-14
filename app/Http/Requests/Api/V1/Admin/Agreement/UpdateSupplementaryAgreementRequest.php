<?php

namespace App\Http\Requests\Api\V1\Admin\Agreement;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\SupplementaryAgreementDTO;

class UpdateSupplementaryAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['sometimes', 'string', 'max:255'],
            'agreement_date' => ['sometimes', 'date_format:Y-m-d'],
            'change_amount' => ['sometimes', 'numeric'],
            'subject_changes' => ['sometimes', 'array'],
            'subject_changes.*' => ['string'],
        ];
    }

    public function toDto(int $contractId): SupplementaryAgreementDTO
    {
        return new SupplementaryAgreementDTO(
            contract_id: $contractId,
            number: $this->validated('number'),
            agreement_date: $this->validated('agreement_date'),
            change_amount: $this->validated('change_amount') !== null ? (float) $this->validated('change_amount') : 0,
            subject_changes: $this->validated('subject_changes') ?? [],
        );
    }
} 