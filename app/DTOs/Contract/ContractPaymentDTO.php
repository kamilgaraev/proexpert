<?php

namespace App\DTOs\Contract;

use App\Enums\Contract\ContractPaymentTypeEnum;

class ContractPaymentDTO
{
    public function __construct(
        // contract_id будет устанавливаться в сервисе
        public readonly string $payment_date, // Y-m-d format
        public readonly float $amount,
        public readonly ContractPaymentTypeEnum $payment_type,
        public readonly ?string $reference_document_number,
        public readonly ?string $description
    ) {}

    public function toArray(): array
    {
        return [
            'payment_date' => $this->payment_date,
            'amount' => $this->amount,
            'payment_type' => $this->payment_type->value,
            'reference_document_number' => $this->reference_document_number,
            'description' => $this->description,
        ];
    }
} 