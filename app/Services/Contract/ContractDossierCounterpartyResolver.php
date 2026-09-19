<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;

final class ContractDossierCounterpartyResolver
{
    public function name(Contract $contract, ?int $organizationId = null): ?string
    {
        $organizationId ??= (int) $contract->organization_id;
        $sides = app(ContractSideResolverService::class)->resolve($contract, $organizationId);
        $counterparty = $sides[$sides['is_income'] ? 'first_party' : 'second_party'] ?? null;
        if ((int) ($counterparty['organization_id'] ?? $counterparty['linked_organization_id'] ?? 0) === $organizationId) {
            return null;
        }
        $resolvedName = trim((string) ($counterparty['name'] ?? ''));
        if ($resolvedName !== '') {
            return $resolvedName;
        }

        $type = $contract->contract_side_type;
        if ($type instanceof ContractSideTypeEnum) {
            $party = $type === ContractSideTypeEnum::GENERAL_CONTRACT
                ? $contract->firstParty
                : $contract->secondParty;
            $name = trim((string) $party?->name);
            if ($name !== '') {
                return $name;
            }

            if ($type === ContractSideTypeEnum::GENERAL_CONTRACT) {
                return $contract->project?->customerCounterparty?->name;
            }

            if ($type->requiresSupplier()) {
                return $contract->supplier?->name;
            }
        }

        return $contract->contractor?->name ?? $contract->supplier?->name;
    }
}
