<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;

interface ResidentialConjuncturePriceRepository
{
    public function officialPriceExists(
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
    ): bool;

    /** @param array<string, mixed> $analysis */
    public function upsert(
        EstimateDatasetVersion $datasetVersion,
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
        string $sourceUnit,
        array $analysis,
    ): void;
}
