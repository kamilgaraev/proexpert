<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\DTOs\Contract\ContractPartyData;
use App\Enums\Contract\ContractPartyRoleEnum;
use App\Enums\Contract\ContractPartySideEnum;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Organization;
use App\Models\Supplier;
use Exception;

class ContractPartySnapshotService
{
    public function syncParties(Contract $contract, bool $force = false): void
    {
        if (!$force && $contract->parties()->exists()) {
            return;
        }

        $contract->loadMissing([
            'organization',
            'project.customerCounterparty',
            'contractor.sourceOrganization',
            'supplier',
        ]);

        $sideType = $contract->contract_side_type instanceof ContractSideTypeEnum
            ? $contract->contract_side_type
            : ($contract->contract_side_type ? ContractSideTypeEnum::tryFrom((string) $contract->contract_side_type) : null);

        if (!$sideType instanceof ContractSideTypeEnum) {
            return;
        }

        [$firstParty, $secondParty] = $this->resolveParties($contract, $sideType);

        $this->persistParty($contract, ContractPartySideEnum::FIRST, $firstParty);
        $this->persistParty($contract, ContractPartySideEnum::SECOND, $secondParty);
    }

    private function resolveParties(Contract $contract, ContractSideTypeEnum $sideType): array
    {
        $owner = $contract->organization;

        if (!$owner instanceof Organization) {
            throw new Exception(trans_message('contract.organization_context_missing'));
        }

        return match ($sideType) {
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR => [
                $this->fromProjectCustomerCounterparty($contract),
                $contract->contractor
                    ? $this->fromContractor($contract, ContractPartyRoleEnum::GENERAL_CONTRACTOR)
                    : $this->fromOrganization($owner, ContractPartyRoleEnum::GENERAL_CONTRACTOR),
            ],
            ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::GENERAL_CONTRACTOR),
                $this->fromContractor($contract, ContractPartyRoleEnum::CONTRACTOR),
            ],
            ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::GENERAL_CONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
            ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::CONTRACTOR),
                $this->fromContractor($contract, ContractPartyRoleEnum::SUBCONTRACTOR),
            ],
            ContractSideTypeEnum::CONTRACTOR_TO_SUPPLIER => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::CONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
            ContractSideTypeEnum::SUBCONTRACTOR_TO_SUPPLIER => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::SUBCONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
        };
    }

    private function fromProjectCustomerCounterparty(Contract $contract): ContractPartyData
    {
        $counterparty = $contract->project?->customerCounterparty;

        if (!$counterparty instanceof Counterparty) {
            throw new Exception(trans_message('contract.customer_counterparty_required'));
        }

        return new ContractPartyData(
            role: ContractPartyRoleEnum::CUSTOMER,
            name: $counterparty->name,
            counterpartyId: $counterparty->id,
            linkedOrganizationId: $counterparty->linked_organization_id,
            legalName: $counterparty->legal_name,
            inn: $counterparty->inn,
            kpp: $counterparty->kpp,
            ogrn: $counterparty->ogrn,
            legalAddress: $counterparty->legal_address,
            email: $counterparty->email,
            phone: $counterparty->phone,
        );
    }

    private function fromOrganization(Organization $organization, ContractPartyRoleEnum $role): ContractPartyData
    {
        $registrationNumber = $this->digitsOnly($organization->registration_number);

        return new ContractPartyData(
            role: $role,
            name: $organization->name,
            linkedOrganizationId: $organization->id,
            legalName: $organization->legal_name,
            inn: $organization->tax_number,
            kpp: $this->kppFromRegistrationNumber($registrationNumber),
            ogrn: $this->ogrnFromRegistrationNumber($registrationNumber),
            legalAddress: $organization->address,
            email: $organization->email,
            phone: $organization->phone,
        );
    }

    private function fromContractor(Contract $contract, ContractPartyRoleEnum $role): ContractPartyData
    {
        if (!$contract->contractor) {
            throw new Exception(trans_message('contract.contractor_required'));
        }

        return new ContractPartyData(
            role: $role,
            name: $contract->contractor->name,
            linkedOrganizationId: $contract->contractor->source_organization_id,
            inn: $contract->contractor->inn,
            kpp: $contract->contractor->kpp,
            legalAddress: $contract->contractor->legal_address,
            email: $contract->contractor->email,
            phone: $contract->contractor->phone,
        );
    }

    private function fromSupplier(Contract $contract, ContractPartyRoleEnum $role): ContractPartyData
    {
        if (!$contract->supplier instanceof Supplier) {
            throw new Exception(trans_message('supplier.not_found'));
        }

        return new ContractPartyData(
            role: $role,
            name: $contract->supplier->name,
            inn: $contract->supplier->inn,
            ogrn: $contract->supplier->ogrn,
            legalAddress: $contract->supplier->address,
            email: $contract->supplier->email,
            phone: $contract->supplier->phone,
        );
    }

    private function persistParty(
        Contract $contract,
        ContractPartySideEnum $side,
        ContractPartyData $partyData
    ): void {
        $contract->parties()->updateOrCreate(
            ['side' => $side->value],
            $partyData->toArray()
        );
    }

    private function digitsOnly(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function kppFromRegistrationNumber(?string $registrationNumber): ?string
    {
        return $registrationNumber !== null && strlen($registrationNumber) === 9
            ? $registrationNumber
            : null;
    }

    private function ogrnFromRegistrationNumber(?string $registrationNumber): ?string
    {
        return $registrationNumber !== null && in_array(strlen($registrationNumber), [13, 15], true)
            ? $registrationNumber
            : null;
    }
}
