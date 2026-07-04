<?php

declare(strict_types=1);

namespace App\DTOs\Contract;

use App\Enums\Contract\ContractPartyRoleEnum;

class ContractPartyData
{
    public function __construct(
        public readonly ContractPartyRoleEnum $role,
        public readonly string $name,
        public readonly ?int $counterpartyId = null,
        public readonly ?int $linkedOrganizationId = null,
        public readonly ?string $legalName = null,
        public readonly ?string $inn = null,
        public readonly ?string $kpp = null,
        public readonly ?string $ogrn = null,
        public readonly ?string $legalAddress = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {
    }

    public function snapshot(): array
    {
        return [
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'counterparty_id' => $this->counterpartyId,
            'linked_organization_id' => $this->linkedOrganizationId,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'ogrn' => $this->ogrn,
            'legal_address' => $this->legalAddress,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'counterparty_id' => $this->counterpartyId,
            'linked_organization_id' => $this->linkedOrganizationId,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'ogrn' => $this->ogrn,
            'legal_address' => $this->legalAddress,
            'email' => $this->email,
            'phone' => $this->phone,
            'snapshot' => $this->snapshot(),
        ];
    }
}
