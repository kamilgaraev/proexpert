<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\Payment;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Illuminate\Validation\Rules\Enum;

class StoreContractPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // $contract = $this->route('contract'); // Contract model instance
        // return Auth::user()->can('addPayment', $contract);
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_type' => ['required', new Enum(ContractPaymentTypeEnum::class)],
            'reference_document_number' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // 'organization_id_for_creation' => ['sometimes', 'integer'] // Временно, если нужно будет извлекать из запроса
        ];
    }

    public function toDto(): ContractPaymentDTO
    {
        return new ContractPaymentDTO(
            payment_date: $this->validated('payment_date'),
            amount: (float) $this->validated('amount'),
            payment_type: ContractPaymentTypeEnum::from($this->validated('payment_type')),
            reference_document_number: $this->validated('reference_document_number'),
            description: $this->validated('description')
        );
    }
} 