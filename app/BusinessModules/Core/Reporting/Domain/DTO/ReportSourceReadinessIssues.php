<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceReadinessIssues
{
    public function __construct(
        public int $missingOpeningCount,
        public int $unknownUnitOrValuationCount,
        public int $invalidTransferPairCount,
        public int $invalidVersionCount,
        public int $invalidHashCount,
    ) {
        if (min($this->toArray()) < 0) {
            throw new InvalidArgumentException('report_source_readiness_issues_invalid');
        }
    }

    public function toArray(): array
    {
        return [
            'missing_opening_count' => $this->missingOpeningCount,
            'unknown_unit_or_valuation_count' => $this->unknownUnitOrValuationCount,
            'invalid_transfer_pair_count' => $this->invalidTransferPairCount,
            'invalid_version_count' => $this->invalidVersionCount,
            'invalid_hash_count' => $this->invalidHashCount,
        ];
    }
}
