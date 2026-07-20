<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use InvalidArgumentException;

final readonly class NormativeContextPinData
{
    public function __construct(
        public int $datasetId,
        public string $datasetVersion,
        public string $applicabilityDate,
        public int $regionId,
        public int $priceZoneId,
        public int $periodId,
        public int $regionalPriceVersionId,
        public string $priceVersion,
        public array $catalogCandidates = [],
        public ?string $catalogContentHash = null,
        public array $supplementaryMaterials = [],
    ) {
        if (min($datasetId, $regionId, $priceZoneId, $periodId, $regionalPriceVersionId) < 1
            || preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $datasetVersion) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $priceVersion) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $applicabilityDate) !== 1
            || ! array_is_list($catalogCandidates) || count($catalogCandidates) > 128
            || ! array_is_list($supplementaryMaterials) || count($supplementaryMaterials) > 64
            || ($catalogContentHash !== null && preg_match('/^[a-f0-9]{64}$/D', $catalogContentHash) !== 1)) {
            throw new InvalidArgumentException('Normative resource context identity is invalid.');
        }
    }

    public function toArray(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'dataset_version' => $this->datasetVersion,
            'applicability_date' => $this->applicabilityDate,
            'region_id' => $this->regionId,
            'price_zone_id' => $this->priceZoneId,
            'period_id' => $this->periodId,
            'regional_price_version_id' => $this->regionalPriceVersionId,
            'price_version' => $this->priceVersion,
            'catalog_content_hash' => $this->catalogContentHash,
            'catalog_candidates' => $this->catalogCandidates,
            'supplementary_materials' => $this->supplementaryMaterials,
        ];
    }
}
