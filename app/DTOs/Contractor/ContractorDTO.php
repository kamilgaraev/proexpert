<?php

namespace App\DTOs\Contractor;

class ContractorDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $contact_person,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $legal_address,
        public readonly ?string $inn,
        public readonly ?string $kpp,
        public readonly ?string $bank_details,
        public readonly ?string $notes
        // organization_id будет добавляться в сервисе
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'legal_address' => $this->legal_address,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'bank_details' => $this->bank_details,
            'notes' => $this->notes,
        ];
    }
} 