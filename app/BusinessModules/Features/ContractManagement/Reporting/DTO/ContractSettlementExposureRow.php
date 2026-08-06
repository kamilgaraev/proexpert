<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\DTO;

use App\BusinessModules\Features\ContractManagement\Reporting\Enums\ContractSettlementPartyType;
use DomainException;

final readonly class ContractSettlementExposureRow
{
    public function __construct(
        public int $contractId,
        public int $allocationId,
        public ?int $projectId,
        public ?int $partyId,
        public ?ContractSettlementPartyType $partyType,
        public string $partyLabel,
        public string $direction,
        public string $currency,
        public int $effectiveMinor,
        public int $acceptedMinor,
        public int $cashMinor,
        public int $settlementMinor,
        public int $unperformedExposureMinor,
        public int $unpaidExposureMinor,
        public string $agingBucket,
        public array $sourceRefs,
    ) {
        if (($partyId === null) !== ($partyType === null) || ($partyId !== null && $partyId < 1)) {
            throw new DomainException('contract_settlement_party_invalid');
        }
        if (trim($partyLabel) === '') {
            throw new DomainException('contract_settlement_party_label_invalid');
        }
    }

    public function partyKey(): ?string
    {
        return $this->partyType?->key($this->partyId ?? 0);
    }

    public function toArray(): array
    {
        return [
            ...get_object_vars($this),
            'partyType' => $this->partyType?->value,
            'partyKey' => $this->partyKey(),
        ];
    }
}
