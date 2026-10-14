<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\DTOs\Contract\ContractPartyData;
use App\DTOs\Contract\ContractRegistrationNumber;
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

        if ($contract->direct_party !== null) {
            return ContractDirectParties::resolve((int) $contract->organization_id, $contract->direct_party, (string) $contract->direct_direction);
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
                    requisites: ContractPartyRequisites::directoryBankDetails($resolved['bank_details'] ?? null),
                );
            }
        }

        return $this->fromOrganization($owner, ContractPartyRoleEnum::CUSTOMER);
    }

    private function fromOrganization(Organization $organization, ContractPartyRoleEnum $role): ContractPartyData
    {
        $registrationNumber = new ContractRegistrationNumber($organization->registration_number);

        return new ContractPartyData(
            role: $role,
            name: $organization->name,
            linkedOrganizationId: $organization->id,
            legalName: $organization->legal_name,
            inn: $organization->tax_number,
            kpp: $registrationNumber->kpp,
            ogrn: $registrationNumber->ogrn,
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
            requisites: ContractPartyRequisites::directoryBankDetails($contract->contractor->bank_details),
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
        $existing = $contract->parties()->where('side', $side->value)->first();
        $data = $partyData->toArray();
        if ($existing !== null) {
            $sameIdentity = $partyData->counterpartyId !== null && $existing->counterparty_id === $partyData->counterpartyId
                || $partyData->linkedOrganizationId !== null && $existing->linked_organization_id === $partyData->linkedOrganizationId
                || $partyData->counterpartyId === null && $partyData->linkedOrganizationId === null
                    && $existing->counterparty_id === null && $existing->linked_organization_id === null
                    && array_map(static fn (string $key) => ($existing->snapshot ?? [])[$key] ?? null, ['name', 'legal_name', 'inn', 'ogrn'])
                        === array_map(static fn (string $key) => $data['snapshot'][$key] ?? null, ['name', 'legal_name', 'inn', 'ogrn']);
            if ($sameIdentity) {
                $data['snapshot'] = [...array_intersect_key($existing->snapshot ?? [], ContractPartyRequisites::LABELS), ...$data['snapshot']];
            }
        }
        $contract->parties()->updateOrCreate(
            ['side' => $side->value],
            $data
        );
    }
}
