<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Enums;

use DomainException;

enum ContractSettlementPartyType: string
{
    case CONTRACTOR = 'contractor';
    case SUPPLIER = 'supplier';

    public function key(int $partyId): string
    {
        if ($partyId < 1) {
            throw new DomainException('contract_settlement_party_invalid');
        }

        return $this->value.':'.$partyId;
    }
}
