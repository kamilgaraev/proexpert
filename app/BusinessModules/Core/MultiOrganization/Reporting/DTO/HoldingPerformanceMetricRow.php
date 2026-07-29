<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

final readonly class HoldingPerformanceMetricRow
{
    public function __construct(
        public int $organizationId,
        public int $holdingId,
        public int $contributorOrganizationId,
        public int $projectId,
        public ?string $currency,
        public string $periodStart,
        public string $monetaryBasis,
        public int $contractedMinor,
        public int $acceptedAccrualMinor,
        public int $cashMinor,
        public string $rowKey,
        public array $sourceRefs,
    ) {
    }

    public function amountMinor(): int
    {
        return match ($this->monetaryBasis) {
            'contracted' => $this->contractedMinor,
            'accepted_accrual' => $this->acceptedAccrualMinor,
            'cash' => $this->cashMinor,
        };
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
