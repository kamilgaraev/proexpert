<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterPaymentDocumentPaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.transaction.register', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string'],
            'bank_transaction_id' => ['nullable', 'string'],
            'transaction_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'budget_override_reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }
}
