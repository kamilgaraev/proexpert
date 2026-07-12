<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

final readonly class PriceSnapshotData
{
    public function __construct(
        public int $regionId,
        public int $zoneId,
        public int $periodId,
        public int $versionId,
        public string $sourceType,
        public string $sourceReference,
        public string $baseAmount,
        public array $coefficients,
        public string $finalAmount,
        public string $currency,
        public string $capturedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'region_id' => $this->regionId,
            'zone_id' => $this->zoneId,
            'period_id' => $this->periodId,
            'version_id' => $this->versionId,
            'source_type' => $this->sourceType,
            'source_reference' => $this->sourceReference,
            'base_amount' => $this->baseAmount,
            'coefficients' => $this->coefficients,
            'final_amount' => $this->finalAmount,
            'currency' => $this->currency,
            'captured_at' => $this->capturedAt,
        ];
    }
}
