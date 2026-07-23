<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;

class RegionalPriceVersionResolver
{
    private const COMPONENT_SOURCE_KINDS = [
        'worker_salary_imported' => ['regional_worker_salary'],
        'building_resources_imported' => [
            'regional_building_resource_index',
            'regional_building_resource_export',
            'regional_building_resource_direct',
        ],
    ];

    private const IMMUTABLE_STATUSES = [
        RegionalPriceStatus::ACTIVE,
        RegionalPriceStatus::SUPERSEDED,
        RegionalPriceStatus::ROLLED_BACK,
    ];

    public function resolveVersionKey(
        string $source,
        int $regionId,
        int $priceZoneId,
        int $periodId,
        string $baseVersionKey,
        string $componentMetadataKey,
        bool $force,
    ): string {
        $versions = EstimateRegionalPriceVersion::query()
            ->where('source', $source)
            ->where('region_id', $regionId)
            ->where('price_zone_id', $priceZoneId)
            ->where('period_id', $periodId)
            ->where(function ($query) use ($baseVersionKey): void {
                $query->where('version_key', $baseVersionKey)
                    ->orWhere('version_key', 'like', $baseVersionKey.'-r%');
            })
            ->latest('id')
            ->get();

        $sourceKinds = self::COMPONENT_SOURCE_KINDS[$componentMetadataKey] ?? [];
        if ($sourceKinds !== [] && $versions->isNotEmpty()) {
            $counts = EstimateResourcePrice::query()
                ->whereIn('regional_price_version_id', $versions->pluck('id'))
                ->whereIn('source_price_kind', $sourceKinds)
                ->selectRaw('regional_price_version_id, count(*) as aggregate')
                ->groupBy('regional_price_version_id')
                ->pluck('aggregate', 'regional_price_version_id');

            $versions->each(static function (EstimateRegionalPriceVersion $version) use ($counts): void {
                $version->setAttribute('component_rows_count', (int) ($counts[$version->id] ?? 0));
            });
        }

        return $this->resolveFromVersions($versions, $baseVersionKey, $componentMetadataKey, $force);
    }

    /**
     * @param  iterable<int, EstimateRegionalPriceVersion>  $versions
     */
    public function resolveFromVersions(
        iterable $versions,
        string $baseVersionKey,
        string $componentMetadataKey,
        bool $force,
    ): string {
        $versions = collect($versions)->values();
        $writable = $versions->first(
            static fn (EstimateRegionalPriceVersion $version): bool => $version->status !== RegionalPriceStatus::FAILED
                && ! in_array($version->status, self::IMMUTABLE_STATUSES, true)
                && (int) $version->getAttribute('component_rows_count') === 0
        );

        if ($writable !== null) {
            return $writable->version_key;
        }

        $latest = $versions->first();

        if ($latest === null) {
            return $baseVersionKey;
        }

        if ($latest->status !== RegionalPriceStatus::FAILED
            && ! $force
            && (bool) ($latest->metadata[$componentMetadataKey] ?? false)) {
            return $latest->version_key;
        }

        $nextRevision = $versions
            ->map(static function (EstimateRegionalPriceVersion $version) use ($baseVersionKey): int {
                if (preg_match('/^'.preg_quote($baseVersionKey, '/').'-r(\d+)$/', $version->version_key, $matches) !== 1) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() + 1;

        return $baseVersionKey.'-r'.$nextRevision;
    }

    public function assertWritable(EstimateRegionalPriceVersion $version): void
    {
        if (in_array($version->status, self::IMMUTABLE_STATUSES, true)) {
            throw new \RuntimeException('Active or historical regional price versions are immutable.');
        }
    }
}
