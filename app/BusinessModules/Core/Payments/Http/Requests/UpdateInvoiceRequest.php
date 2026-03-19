<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.edit', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return [
            'due_date' => 'sometimes|date',
            'description' => 'sometimes|nullable|string|max:1000',
            'payment_terms' => 'sometimes|nullable|string|max:500',
            'payment_purpose' => 'sometimes|nullable|string|max:500',
            'bank_account' => 'sometimes|nullable|string|size:20|regex:/^\d{20}$/',
            'bank_bik' => 'sometimes|nullable|string|size:9|regex:/^\d{9}$/',
            'bank_name' => 'sometimes|nullable|string|max:255',
            'bank_correspondent_account' => 'sometimes|nullable|string|size:20|regex:/^\d{20}$/',
            'notes' => 'sometimes|nullable|string|max:1000',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge(
            collect($this->all())
                ->map(fn ($value) => $value === '' ? null : $value)
                ->toArray()
        );
    }
}
