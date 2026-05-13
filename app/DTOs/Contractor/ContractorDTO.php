<?php

declare(strict_types=1);

namespace App\DTOs\Contractor;

class ContractorDTO
{
    /**
     * @param array<int, string>|null $providedFields
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $contact_person,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $legal_address,
        public readonly ?string $inn,
        public readonly ?string $kpp,
        public readonly ?string $bank_details,
        public readonly ?string $notes,
        private readonly ?array $providedFields = null
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        $data = [
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

        if ($this->providedFields === null) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->providedFields));
    }
}
