<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Http\Responses\MobileResponse;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MobileStorePaymentDocumentRequest extends StorePaymentDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
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
