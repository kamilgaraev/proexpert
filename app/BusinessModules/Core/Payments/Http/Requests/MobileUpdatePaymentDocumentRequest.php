<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Http\Responses\MobileResponse;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class MobileUpdatePaymentDocumentRequest extends UpdatePaymentDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return array_merge(parent::rules(), [
            'payer_organization_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('organizations', 'id')->where('id', $organizationId),
            ],
            'payer_contractor_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'payee_organization_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('organizations', 'id')->where('id', $organizationId),
            ],
            'payee_contractor_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('payments.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
