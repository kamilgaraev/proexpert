<?php

declare(strict_types=1);

namespace App\DTOs\Counterparty;

class CounterpartyData
{
    /**
     * @param array<int, string>|null $providedFields
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName,
        public readonly ?string $inn,
        public readonly ?string $kpp,
        public readonly ?string $ogrn,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $contactPerson,
        public readonly ?string $legalAddress,
        public readonly ?string $postalAddress,
        public readonly ?array $bankDetails,
        public readonly ?array $roles,
        public readonly ?int $linkedOrganizationId,
        public readonly string $source = 'manual',
        public readonly bool $isActive = true,
        private readonly ?array $providedFields = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'ogrn' => $this->ogrn,
            'email' => $this->email,
            'phone' => $this->phone,
            'contact_person' => $this->contactPerson,
            'legal_address' => $this->legalAddress,
            'postal_address' => $this->postalAddress,
            'bank_details' => $this->bankDetails,
            'roles' => $this->roles,
            'linked_organization_id' => $this->linkedOrganizationId,
            'source' => $this->source,
            'is_active' => $this->isActive,
        ];

        if ($this->providedFields === null) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->providedFields));
    }
}
