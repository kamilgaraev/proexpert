<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;

final class EloquentResidentialConjuncturePriceRepository implements ResidentialConjuncturePriceRepository
{
    private const OFFICIAL_SOURCE_KINDS = [
        'regional_building_resource_export',
        'regional_building_resource_direct',
        'regional_building_resource_index',
    ];

    public function officialPriceExists(
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
    ): bool {
        return EstimateResourcePrice::query()
            ->where('regional_price_version_id', $regionalVersion->id)
            ->where('resource_code', $resourceCode)
            ->where('price_type', EstimateResourceType::MATERIAL->value)
            ->whereIn('source_price_kind', self::OFFICIAL_SOURCE_KINDS)
            ->where('base_price', '>', 0)
            ->exists();
    }

    public function upsert(
        EstimateDatasetVersion $datasetVersion,
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
        string $sourceUnit,
        array $analysis,
    ): void {
        EstimateResourcePrice::query()->updateOrCreate(
            [
                'dataset_version_id' => $datasetVersion->id,
                'resource_code' => $resourceCode,
                'price_type' => EstimateResourceType::MATERIAL->value,
            ],
            [
                'regional_price_version_id' => $regionalVersion->id,
                'region_id' => $regionalVersion->region_id,
                'price_zone_id' => $regionalVersion->price_zone_id,
                'period_id' => $regionalVersion->period_id,
                'construction_resource_id' => null,
                'resource_name' => $analysis['resource_name'],
                'unit' => $sourceUnit,
                'base_price' => $analysis['median_price'],
                'source_price_kind' => 'conjuncture_analysis',
                'raw_payload' => [
                    'source' => 'conjuncture_analysis',
                    'analysis' => $analysis,
                ],
            ],
        );
    }
}
