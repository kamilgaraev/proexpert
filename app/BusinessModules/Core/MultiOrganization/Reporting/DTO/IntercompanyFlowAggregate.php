<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

final readonly class IntercompanyFlowAggregate
{
    public function __construct(
        public string $currency,
        public int $internalMinor,
        public int $externalMinor,
        public int $unclassifiedMinor,
        public int $totalMinor,
        public ?string $internalShare,
        public ?string $externalShare,
        public ?string $unclassifiedShare,
        public ?int $linkedSpreadMinor,
    ) {}

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'internal_minor' => $this->internalMinor,
            'external_minor' => $this->externalMinor,
            'unclassified_minor' => $this->unclassifiedMinor,
            'total_minor' => $this->totalMinor,
            'internal_share' => $this->internalShare,
            'external_share' => $this->externalShare,
            'unclassified_share' => $this->unclassifiedShare,
            'linked_spread_minor' => $this->linkedSpreadMinor,
        ];
    }
}
