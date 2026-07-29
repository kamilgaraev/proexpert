<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use InvalidArgumentException;

final readonly class ComparableProposalVersion
{
    public function __construct(
        public int $proposalVersionId,
        public int $supplierId,
        public int $amountMinor,
        public string $currency,
        public string $materialSpecificationHash,
        public string $quantity,
        public string $unit,
        public string $vatBasis,
        public string $freightBasis,
    ) {
        if ($amountMinor < 0 || $currency === '') {
            throw new InvalidArgumentException('Proposal amount and currency are invalid.');
        }
    }

    public function comparisonKey(): string
    {
        return implode('|', [
            $this->materialSpecificationHash,
            $this->quantity,
            $this->unit,
            $this->vatBasis,
            $this->freightBasis,
            $this->currency,
        ]);
    }
}
