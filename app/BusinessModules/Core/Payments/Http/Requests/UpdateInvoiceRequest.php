<?php

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        // Конвертировать пустые строки в null
        $this->merge(
            collect($this->all())
                ->map(fn($value) => $value === '' ? null : $value)
                ->toArray()
        );
    }
}

