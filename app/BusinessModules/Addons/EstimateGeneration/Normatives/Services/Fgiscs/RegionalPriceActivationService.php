<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceActivation;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegionalPriceActivationService
{
    public function activate(EstimateRegionalPriceVersion $version, string $reason = 'auto_quality_passed'): EstimateRegionalPriceActivation
    {
        return DB::transaction(function () use ($version, $reason): EstimateRegionalPriceActivation {
            $activation = EstimateRegionalPriceActivation::query()
                ->where('region_id', $version->region_id)
                ->where('price_zone_id', $version->price_zone_id)
                ->lockForUpdate()
                ->first();

            $previousVersion = $activation?->activeVersion;

            if ($previousVersion !== null && $previousVersion->id !== $version->id) {
                $previousVersion->update([
                    'status' => RegionalPriceStatus::SUPERSEDED->value,
                    'superseded_at' => now(),
                ]);
            }

            $version->update([
                'status' => RegionalPriceStatus::ACTIVE->value,
                'activated_at' => now(),
                'superseded_at' => null,
                'rolled_back_at' => null,
            ]);

            return EstimateRegionalPriceActivation::query()->updateOrCreate(
                [
                    'region_id' => $version->region_id,
                    'price_zone_id' => $version->price_zone_id,
                ],
                [
                    'active_version_id' => $version->id,
                    'previous_version_id' => $previousVersion?->id !== $version->id ? $previousVersion?->id : $activation?->previous_version_id,
                    'activated_at' => now(),
                    'activation_reason' => $reason,
                ]
            );
        });
    }

    public function rollback(string $regionCode, int $priceZoneId): EstimateRegionalPriceActivation
    {
        return DB::transaction(function () use ($regionCode, $priceZoneId): EstimateRegionalPriceActivation {
            $activation = EstimateRegionalPriceActivation::query()
                ->whereHas('activeVersion.region', static fn ($query) => $query->where('code', $regionCode))
                ->whereHas('activeVersion.priceZone', static fn ($query) => $query->where('fgiscs_price_zone_id', $priceZoneId))
                ->lockForUpdate()
                ->first();

            if ($activation === null || $activation->previousVersion === null) {
                throw new RuntimeException('Нет предыдущего квартала для отката.');
            }

            $current = $activation->activeVersion;
            $previous = $activation->previousVersion;

            $current->update([
                'status' => RegionalPriceStatus::ROLLED_BACK->value,
                'rolled_back_at' => now(),
            ]);

            $previous->update([
                'status' => RegionalPriceStatus::ACTIVE->value,
                'activated_at' => now(),
                'superseded_at' => null,
                'rolled_back_at' => null,
            ]);

            $activation->update([
                'active_version_id' => $previous->id,
                'previous_version_id' => $current->id,
                'activated_at' => now(),
                'activation_reason' => 'manual_rollback',
            ]);

            return $activation->fresh(['activeVersion.period', 'previousVersion.period']);
        });
    }
}
