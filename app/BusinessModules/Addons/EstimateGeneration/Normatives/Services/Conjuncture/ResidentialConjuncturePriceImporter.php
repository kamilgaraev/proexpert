<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;

final class ResidentialConjuncturePriceImporter
{
    private readonly ResidentialConjuncturePriceRepository $prices;

    public function __construct(
        private readonly ResidentialProjectMaterialCatalog $catalog = new ResidentialProjectMaterialCatalog,
        private readonly ResidentialConjunctureOfferProvider $offers = new ResidentialConjunctureOfferProvider,
        ?ResidentialConjuncturePriceRepository $prices = null,
    ) {
        $this->prices = $prices ?? new EloquentResidentialConjuncturePriceRepository;
    }

    /** @return array{inserted:int,official:int,missing:int,resource_codes:list<string>} */
    public function import(
        EstimateDatasetVersion $datasetVersion,
        EstimateRegionalPriceVersion $regionalVersion,
        string $regionCode,
    ): array {
        $inserted = 0;
        $official = 0;
        $missing = 0;
        $resourceCodes = [];

        foreach ($this->catalog->conjunctureRequirements() as $requirement) {
            $resourceCode = trim((string) ($requirement['resource_code'] ?? ''));
            $sourceUnit = trim((string) ($requirement['source_unit'] ?? ''));
            $analysisKey = trim((string) ($requirement['conjuncture_analysis_key'] ?? ''));

            if ($this->prices->officialPriceExists($regionalVersion, $resourceCode)) {
                $official++;

                continue;
            }

            $analysis = $this->offers->resolve($analysisKey, $resourceCode, $sourceUnit, $regionCode);
            if ($analysis === null) {
                $missing++;

                continue;
            }

            $this->prices->upsert(
                $datasetVersion,
                $regionalVersion,
                $resourceCode,
                $sourceUnit,
                $analysis,
            );
            $inserted++;
            $resourceCodes[] = $resourceCode;
        }

        return [
            'inserted' => $inserted,
            'official' => $official,
            'missing' => $missing,
            'resource_codes' => $resourceCodes,
        ];
    }
}
