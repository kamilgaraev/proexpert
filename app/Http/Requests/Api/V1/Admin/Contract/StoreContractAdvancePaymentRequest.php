<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\ContractAdvancePaymentDTO;

class StoreContractAdvancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'payment_date' => 'nullable|date',
        ];
    }

    public function toDto(): ContractAdvancePaymentDTO
    {
        return new ContractAdvancePaymentDTO(
            contract_id: $this->route('contract'),
            amount: (float) $this->validated('amount'),
            description: $this->validated('description'),
            payment_date: $this->validated('payment_date'),
        );
    }
}
