<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Support;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;

class EstimateRegionalPriceOptionPresenter
{
    /**
     * @param array<int, int|string> $activeVersionIds
     * @return array<string, mixed>
     */
    public function version(EstimateRegionalPriceVersion $version, array $activeVersionIds): array
    {
        return [
            'id' => $version->id,
            'source' => $version->source,
            'version_key' => $version->version_key,
            'status' => $version->status?->value,
            'is_active' => in_array($version->id, $activeVersionIds, true),
            'region_id' => $version->region_id,
            'price_zone_id' => $version->price_zone_id,
            'period_id' => $version->period_id,
            'period_name' => $this->periodName($version->period),
            'year' => $version->period?->year,
            'quarter' => $version->period?->quarter,
            'activated_at' => $version->activated_at?->toISOString(),
        ];
    }

    private function periodName(?EstimatePricePeriod $period): ?string
    {
        if ($period === null) {
            return null;
        }

        if ($period->year !== null && $period->quarter !== null) {
            return sprintf('%d квартал %d г.', $period->quarter, $period->year);
        }

        return $period->name;
    }
}
