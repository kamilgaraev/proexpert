<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Agreement;

use App\DTOs\SupplementaryAgreementDTO;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class UpdateSupplementaryAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $agreementId = $this->route('agreement');
        $contractId = null;

        if ($agreementId) {
            $agreement = \App\Models\SupplementaryAgreement::find($agreementId);
            $contractId = $agreement?->contract_id;
        }

        return [
            'number' => [
                'sometimes',
                'string',
                'max:255',
                $contractId && $agreementId
                    ? Rule::unique('supplementary_agreements', 'number')
                        ->ignore($agreementId)
                        ->where('contract_id', $contractId)
                        ->whereNull('deleted_at')
                    : 'sometimes',
            ],
            'agreement_date' => ['sometimes', 'date_format:Y-m-d'],
            'change_amount' => ['sometimes', 'nullable', 'numeric'],
            'supersede_agreement_ids' => ['sometimes', 'nullable', 'array'],
            'supersede_agreement_ids.*' => [
                'required',
                'integer',
                'exists:supplementary_agreements,id',
                function (string $attribute, mixed $value, callable $fail): void {
                    $currentAgreement = $this->route('agreement');
                    $contractId = $currentAgreement
                        ? \App\Models\SupplementaryAgreement::find($currentAgreement)?->contract_id
                        : null;

                    if ($contractId) {
                        $agreement = \App\Models\SupplementaryAgreement::find($value);
                        if ($agreement && (int) $agreement->contract_id !== (int) $contractId) {
                            $fail(trans_message('agreements.validation.agreement_belongs_to_contract', ['id' => $value]));
                        }
                    }
                },
            ],
            'subject_changes' => ['sometimes', 'array'],
            'subject_changes.*' => ['string'],
            'subcontract_changes' => ['sometimes', 'nullable', 'array'],
            'gp_changes' => ['sometimes', 'nullable', 'array'],
            'advance_changes' => ['sometimes', 'nullable', 'array'],
            'advance_changes.*.payment_id' => ['required', 'integer', 'exists:invoices,id'],
            'advance_changes.*.new_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => trans_message('agreements.validation.number_unique'),
            'supersede_agreement_ids.*.exists' => trans_message('agreements.validation.superseded_not_found'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            AdminResponse::error(
                trans_message('agreements.validation_error'),
                422,
                $errors
            )
        );
    }

    public function toDto(int $contractId): SupplementaryAgreementDTO
    {
        $supersedeIds = $this->validated('supersede_agreement_ids');
        if ($supersedeIds !== null) {
            $supersedeIds = array_map('intval', $supersedeIds);
        }

        return new SupplementaryAgreementDTO(
            contract_id: $contractId,
            number: $this->validated('number'),
            agreement_date: $this->validated('agreement_date'),
            change_amount: $this->validated('change_amount') !== null ? (float) $this->validated('change_amount') : null,
            subject_changes: $this->validated('subject_changes') ?? [],
            subcontract_changes: $this->validated('subcontract_changes'),
            gp_changes: $this->validated('gp_changes'),
            advance_changes: $this->validated('advance_changes'),
            supersede_agreement_ids: $supersedeIds,
        );
    }
}
