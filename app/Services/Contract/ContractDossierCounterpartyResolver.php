<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;

final class ContractDossierCounterpartyResolver
{
    public function name(Contract $contract): ?string
    {
        $type = $contract->contract_side_type;
        if ($type instanceof ContractSideTypeEnum) {
            $party = $type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
                ? $contract->firstParty
                : $contract->secondParty;
            $name = trim((string) $party?->name);
            if ($name !== '') {
                return $name;
            }

            if ($type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
                return $contract->project?->customerCounterparty?->name;
            }

            if ($type->requiresSupplier()) {
                return $contract->supplier?->name;
            }
        }

        return $contract->contractor?->name ?? $contract->supplier?->name;
    }
}
