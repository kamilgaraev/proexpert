<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract\Payment;

use App\DTOs\Contract\ContractPaymentDTO;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateContractPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
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
        ];
    }

    public function toDto(): ContractPaymentDTO
    {
        $validatedData = $this->validated();

        return new ContractPaymentDTO(
            payment_date: $validatedData['payment_date'],
            amount: (float) $validatedData['amount'],
            payment_type: ContractPaymentTypeEnum::from($validatedData['payment_type']),
            reference_document_number: $validatedData['reference_document_number'] ?? null,
            description: $validatedData['description'] ?? null
        );
    }
}
