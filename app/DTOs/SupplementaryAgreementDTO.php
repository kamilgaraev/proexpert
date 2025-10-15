<?php

namespace App\DTOs;

class SupplementaryAgreementDTO
{
    public function __construct(
        public readonly int $contract_id,
        public readonly string $number,
        public readonly string $agreement_date, // Y-m-d
        public readonly float $change_amount,
        public readonly array $subject_changes,
        public readonly ?array $subcontract_changes,
        public readonly ?array $gp_changes,
        public readonly ?array $advance_changes,
    ) {}

    public function toArray(): array
    {
        return [
            'contract_id' => $this->contract_id,
            'number' => $this->number,
            'agreement_date' => $this->agreement_date,
            'change_amount' => $this->change_amount,
            'subject_changes' => $this->subject_changes,
            'subcontract_changes' => $this->subcontract_changes,
            'gp_changes' => $this->gp_changes,
            'advance_changes' => $this->advance_changes,
        ];
    }
} 