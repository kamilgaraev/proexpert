<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class MobilePaymentDocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0 && $this->user() instanceof \App\Models\User;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(array_map(
                static fn (PaymentDocumentStatus $status): string => $status->value,
                PaymentDocumentStatus::cases()
            ))],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
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
