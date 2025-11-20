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
            'organization_id' => 'required|exists:organizations,id',
            'project_id' => 'nullable|exists:projects,id',
            'counterparty_organization_id' => 'nullable|exists:organizations,id',
            'contractor_id' => 'nullable|exists:contractors,id',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'direction' => ['required', new Enum(InvoiceDirection::class)],
            'invoice_type' => ['required', new Enum(InvoiceType::class)],
            'invoiceable_type' => 'nullable|string|in:App\\Models\\Contract,App\\Models\\Project',
            'invoiceable_id' => 'nullable|integer',
            'template_id' => 'nullable|string|in:advance_30,advance_50,advance_70,advance_100,custom_advance,progress,final_100,custom_final,act',
            'total_amount' => 'required_without:template_id|numeric|min:0.01',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:1000',
            'payment_terms' => 'nullable|string|max:500',
            'bank_account' => 'nullable|string|size:20|regex:/^\d{20}$/',
            'bank_bik' => 'nullable|string|size:9|regex:/^\d{9}$/',
            'bank_name' => 'nullable|string|max:255',
            'bank_correspondent_account' => 'nullable|string|size:20|regex:/^\d{20}$/',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'organization_id' => request()->attributes->get('current_organization_id'),
        ]);
    }
}

