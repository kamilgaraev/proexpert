<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Support\Reporting\CanonicalLineageSummary;

final readonly class AcceptedProductionUniverseEntry
{
    public function __construct(
        public array $candidate,
        private ProductionAcceptanceEvent $latest,
        public ProductionAcceptanceFact $fact,
        public CanonicalLineageSummary $lineage,
    ) {}

    public function latestEvent(): ?ProductionAcceptanceEvent
    {
        return $this->latest;
    }

    public function canonicalIdentity(): array
    {
        return [
            'candidate' => $this->candidate,
            'lineage' => $this->lineage->canonicalIdentity(),
        ];
    }
}
