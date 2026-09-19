<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\DTOs\Contract\ContractPartyData;
use App\Enums\Contract\ContractPartyRoleEnum;
use App\Enums\Contract\ContractPartySideEnum;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Supplier;
use DomainException as Exception;

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

        if (!$contract->is_self_execution && $firstParty->linkedOrganizationId !== null
            && $firstParty->linkedOrganizationId === $secondParty->linkedOrganizationId) {
            throw new Exception(trans_message('contracts.direction_mismatch'));
        }

        $this->persistParty($contract, ContractPartySideEnum::FIRST, $firstParty);
        $this->persistParty($contract, ContractPartySideEnum::SECOND, $secondParty);
    }

    public function resolveParties(Contract $contract, ContractSideTypeEnum $sideType): array
    {
        $owner = $contract->organization;

        if (!$owner instanceof Organization) {
            throw new Exception(trans_message('contract.organization_context_missing'));
        }

        return match ($sideType) {
            ContractSideTypeEnum::GENERAL_CONTRACT => [
                $this->fromProjectCustomer($contract, $owner),
                $contract->contractor
                    ? $this->fromContractor($contract, ContractPartyRoleEnum::GENERAL_CONTRACTOR)
                    : $this->fromOrganization($owner, ContractPartyRoleEnum::GENERAL_CONTRACTOR),
            ],
            ContractSideTypeEnum::CONTRACT => [
                $this->fromOrganization(
                    $this->resolveSuperiorOrganization(
                        $contract,
                        $owner,
                        $contract->project?->contracting_scheme === 'direct'
                            ? ProjectOrganizationRole::CUSTOMER : ProjectOrganizationRole::GENERAL_CONTRACTOR
                    ),
                    $contract->project?->contracting_scheme === 'direct'
                        ? ContractPartyRoleEnum::CUSTOMER : ContractPartyRoleEnum::GENERAL_CONTRACTOR
                ),
                $this->fromContractor($contract, ContractPartyRoleEnum::CONTRACTOR),
            ],
            ContractSideTypeEnum::GENERAL_CONTRACTOR_SUPPLY => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::GENERAL_CONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
            ContractSideTypeEnum::SUBCONTRACT => [
                $this->fromOrganization(
                    $this->resolveSuperiorOrganization($contract, $owner, ProjectOrganizationRole::CONTRACTOR),
                    ContractPartyRoleEnum::CONTRACTOR
                ),
                $this->fromContractor($contract, ContractPartyRoleEnum::SUBCONTRACTOR),
            ],
            ContractSideTypeEnum::CONTRACTOR_SUPPLY => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::CONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
            ContractSideTypeEnum::SUBCONTRACTOR_SUPPLY => [
                $this->fromOrganization($owner, ContractPartyRoleEnum::SUBCONTRACTOR),
                $this->fromSupplier($contract, ContractPartyRoleEnum::SUPPLIER),
            ],
        };
    }

    private function resolveSuperiorOrganization(
        Contract $contract,
        Organization $owner,
        ProjectOrganizationRole $role
    ): Organization {
        if ((int) $contract->contractor?->source_organization_id !== (int) $owner->id || $contract->is_self_execution) {
            return $owner;
        }
        $project = $contract->project;
        if ($project === null) {
            throw new Exception(trans_message('contracts.superior_required'));
        }

        $superior = app(ProjectContractPartyResolver::class)->selectedActiveParticipant(
            $project,
            $role,
            $contract->superior_organization_id !== null ? (int) $contract->superior_organization_id : null,
        );
        if ($superior === null || (int) $superior->id === (int) $owner->id) {
            throw new Exception(trans_message('contracts.superior_required'));
        }

        return $superior;
    }

    private function fromProjectCustomer(Contract $contract, Organization $owner): ContractPartyData
    {
        if ($contract->project !== null) {
            $resolved = app(\App\Services\Project\ProjectCustomerResolverService::class)->resolveLegalCustomer($contract->project);
            if (!empty($resolved['name'])) {
                return new ContractPartyData(
                    role: ContractPartyRoleEnum::CUSTOMER,
                    name: (string) $resolved['name'],
                    counterpartyId: $resolved['counterparty_id'] ?? null,
                    linkedOrganizationId: $resolved['linked_organization_id'] ?? null,
                    legalName: (string) ($resolved['legal_name'] ?? $resolved['name']),
                    inn: $resolved['inn'] ?? null,
                    kpp: $resolved['kpp'] ?? null,
                    ogrn: $resolved['ogrn'] ?? null,
                    legalAddress: $resolved['legal_address'] ?? null,
                    email: $resolved['email'] ?? null,
                    phone: $resolved['phone'] ?? null,
                );
            }
        }

        return $this->fromOrganization($owner, ContractPartyRoleEnum::CUSTOMER);
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
