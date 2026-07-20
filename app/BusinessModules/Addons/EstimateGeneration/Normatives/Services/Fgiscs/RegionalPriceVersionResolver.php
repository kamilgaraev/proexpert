<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;

class RegionalPriceVersionResolver
{
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

        $writable = $versions->first(
            static fn (EstimateRegionalPriceVersion $version): bool => ! in_array($version->status, self::IMMUTABLE_STATUSES, true)
        );

        if ($writable !== null) {
            return $writable->version_key;
        }

        $latest = $versions->first();

        if ($latest === null) {
            return $baseVersionKey;
        }

        if (! $force && (bool) ($latest->metadata[$componentMetadataKey] ?? false)) {
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
