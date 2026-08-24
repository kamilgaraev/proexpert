<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.transaction.refund', [
                'organization_id' => $organizationId,
            ]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'refund_date' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => trim((string) $this->header('Idempotency-Key')),
        ]);
    }
}
