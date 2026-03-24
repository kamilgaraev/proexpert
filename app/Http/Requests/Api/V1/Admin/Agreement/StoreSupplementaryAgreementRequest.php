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
            'number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('supplementary_agreements', 'number')
                    ->where('contract_id', $contractId)
                    ->whereNull('deleted_at'),
            ],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'change_amount' => ['nullable', 'numeric'],
            'supersede_agreement_ids' => ['nullable', 'array'],
            'supersede_agreement_ids.*' => [
                'required',
                'integer',
                'exists:supplementary_agreements,id',
                function (string $attribute, mixed $value, callable $fail) use ($contractId): void {
                    $agreement = \App\Models\SupplementaryAgreement::find($value);

                    if ($agreement && $contractId && (int) $agreement->contract_id !== (int) $contractId) {
                        $fail(trans_message('agreements.validation.agreement_belongs_to_contract', ['id' => $value]));
                    }
                },
            ],
            'subject_changes' => ['required', 'array'],
            'subject_changes.*' => ['string'],
            'subcontract_changes' => ['nullable', 'array'],
            'gp_changes' => ['nullable', 'array'],
            'advance_changes' => ['nullable', 'array'],
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
            supersede_agreement_ids: $supersedeIds,
        );
    }
}
