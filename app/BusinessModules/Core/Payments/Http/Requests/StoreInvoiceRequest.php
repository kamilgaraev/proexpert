<?php

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Проверка прав в сервисе
    }

    public function rules(): array
    {
        return [
            'project_id' => 'nullable|exists:projects,id',
            'counterparty_organization_id' => 'nullable|exists:organizations,id',
            'contractor_id' => 'nullable|exists:contractors,id',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'direction' => ['required', new Enum(InvoiceDirection::class)],
            'invoice_type' => ['required', new Enum(InvoiceType::class)],
            'total_amount' => 'required|numeric|min:0.01',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:1000',
            'payment_terms' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'organization_id' => $this->attributes->get('current_organization_id'),
        ]);
    }
}

