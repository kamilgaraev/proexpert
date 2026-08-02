<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\DTO;

final readonly class ContractSettlementExposureRow
{
    public function __construct(
        public int $contractId,
        public int $allocationId,
        public ?int $projectId,
        public ?int $partyId,
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
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
