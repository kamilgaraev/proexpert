<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\DTO;

use DateTimeImmutable;
use DomainException;

final readonly class ContractSettlementInput
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
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $asOf,
        public array $sourceRefs,
    ) {
        if ($contractId < 1 || $allocationId < 1 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('contract_settlement_input_invalid');
        }
        if (!in_array($direction, ['receivable', 'payable'], true)) {
            throw new DomainException('contract_settlement_direction_invalid');
        }
        if ($acceptedMinor < 0 || $cashMinor < 0 || $effectiveMinor < 0) {
            throw new DomainException('contract_settlement_amount_invalid');
        }
    }
}
