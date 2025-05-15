<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\Payment;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Illuminate\Validation\Rules\Enum;

class UpdateContractPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // $payment = $this->route('payment'); // Route model binding for payment
        // return Auth::user()->can('update', $payment);
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'payment_type' => ['sometimes', 'required', new Enum(ContractPaymentTypeEnum::class)],
            'reference_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            // 'organization_id_for_update' => ['sometimes', 'integer'] // Временно
        ];
    }

    public function toDto(): ContractPaymentDTO
    {
        $validatedData = $this->validated();
        // Для DTO нужно передать все параметры конструктора.
        // Если какие-то поля не пришли (так как 'sometimes'), 
        // их нужно либо извлечь из существующей модели в сервисе, либо DTO должен уметь это обрабатывать.
        // ContractPaymentDTO ожидает все поля.
        // Мы передадим null для необязательных полей, если они не были валидированы (т.е. не пришли в запросе на обновление)
        // Для payment_type, если он не пришел, возникнет ошибка при ContractPaymentTypeEnum::from.
        // Предполагается, что если payment_type обновляется, он должен быть в validatedData.
        // Либо нужно будет получать существующее значение из модели в сервисе/контроллере.

        return new ContractPaymentDTO(
            payment_date: $validatedData['payment_date'] ?? $this->route('payment')->payment_date, // Пример: берем старое значение, если не обновляется
            amount: isset($validatedData['amount']) ? (float) $validatedData['amount'] : $this->route('payment')->amount,
            payment_type: isset($validatedData['payment_type']) 
                            ? ContractPaymentTypeEnum::from($validatedData['payment_type']) 
                            : $this->route('payment')->payment_type,
            reference_document_number: $validatedData['reference_document_number'] ?? $this->route('payment')->reference_document_number,
            description: $validatedData['description'] ?? $this->route('payment')->description
        );
    }
} 