<?php

namespace App\DTOs;

class ContractAdvancePaymentDTO
{
    public function __construct(
        public readonly int $contract_id,
        public readonly float $amount,
        public readonly ?string $description,
        public readonly ?string $payment_date,
    ) {}

    public function toArray(): array
    {
        return [
            'contract_id' => $this->contract_id,
            'amount' => $this->amount,
            'description' => $this->description,
            'payment_date' => $this->payment_date,
        ];
    }
}
