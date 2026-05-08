<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use Illuminate\Database\Eloquent\Builder;

class EstimateGenerationRegionalContextResolver
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolve(array $input): array
    {
        $selectedVersionId = $this->nullableInt($input['estimate_regional_price_version_id'] ?? null);

        if ($selectedVersionId !== null) {
            $version = EstimateRegionalPriceVersion::query()
                ->with(['region', 'priceZone', 'period'])
                ->find($selectedVersionId);

            if ($version !== null) {
                return $this->contextFromVersion($version, 'selected');
            }
        }

        $regionId = $this->nullableInt($input['region_id'] ?? null);
        $priceZoneId = $this->nullableInt($input['price_zone_id'] ?? null);
        $periodId = $this->nullableInt($input['period_id'] ?? null);

        $period = $periodId !== null
            ? EstimatePricePeriod::query()->find($periodId)
            : $this->detectPeriod($this->inputText($input));

        $region = $regionId !== null
            ? EstimateRegion::query()->find($regionId)
            : $this->detectRegion($this->inputText($input));

        if ($region !== null) {
            $version = $this->findActiveVersion($region->id, $priceZoneId, $period?->id, $period?->year, $period?->quarter);

            if ($version !== null) {
                return $this->contextFromVersion($version, $regionId !== null || $periodId !== null ? 'selected' : 'text_detected');
            }

            return [
                'estimate_regional_price_version_id' => null,
                'region_id' => $region->id,
                'region_name' => $region->name,
                'price_zone_id' => $priceZoneId,
                'price_zone_name' => null,
                'period_id' => $period?->id,
                'period_name' => $period?->name,
                'year' => $period?->year,
                'quarter' => $period?->quarter,
                'source' => 'text_detected',
                'status' => 'price_version_not_found',
            ];
        }

        return [
            'estimate_regional_price_version_id' => null,
            'region_id' => null,
            'region_name' => $this->nullableString($input['region'] ?? null),
            'price_zone_id' => $priceZoneId,
            'price_zone_name' => null,
            'period_id' => $period?->id,
            'period_name' => $period?->name,
            'year' => $period?->year,
            'quarter' => $period?->quarter,
            'source' => 'not_resolved',
            'status' => 'regional_context_missing',
        ];
    }

    private function findActiveVersion(int $regionId, ?int $priceZoneId, ?int $periodId, ?int $year, ?int $quarter): ?EstimateRegionalPriceVersion
    {
        $query = EstimateRegionalPriceVersion::query()
            ->with(['region', 'priceZone', 'period'])
            ->where('region_id', $regionId)
            ->whereIn('status', [
                RegionalPriceStatus::ACTIVE->value,
                RegionalPriceStatus::CHECKED->value,
                RegionalPriceStatus::PARSED->value,
            ]);

        if ($priceZoneId !== null) {
            $query->where('price_zone_id', $priceZoneId);
        }

        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        } elseif ($year !== null && $quarter !== null) {
            $query->whereHas('period', static function (Builder $query) use ($year, $quarter): void {
                $query->where('year', $year)->where('quarter', $quarter);
            });
        }

        return $query
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'checked' THEN 1 WHEN 'parsed' THEN 2 ELSE 3 END")
            ->latest('id')
            ->first();
    }

    private function detectRegion(string $text): ?EstimateRegion
    {
        $normalized = mb_strtolower($text);

        $aliases = [
            'московская область' => ['московская область', 'подмосков'],
            'москва' => ['москва'],
            'республика татарстан' => ['республика татарстан', 'татарстан'],
        ];

        foreach ($aliases as $regionName => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return EstimateRegion::query()
                        ->whereRaw('LOWER(name) = ?', [$regionName])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $regionName . '%'])
                        ->first();
                }
            }
        }

        $regions = EstimateRegion::query()
            ->where('is_supported', true)
            ->orderByRaw('LENGTH(name) DESC')
            ->get(['id', 'name', 'code']);

        foreach ($regions as $region) {
            if (str_contains($normalized, mb_strtolower($region->name))) {
                return $region;
            }
        }

        return null;
    }

    private function detectPeriod(string $text): ?EstimatePricePeriod
    {
        $normalized = mb_strtolower($text);
        $year = null;
        $quarter = null;

        if (preg_match('/(20\d{2})/u', $normalized, $matches) === 1) {
            $year = (int) $matches[1];
        }

        if (preg_match('/(?<!\d)([1-4])\s*(?:кв|квартал)/u', $normalized, $matches) === 1) {
            $quarter = (int) $matches[1];
        } elseif (preg_match('/\b(i|ii|iii|iv)\s*(?:кв|квартал)/ui', $normalized, $matches) === 1) {
            $quarter = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4][mb_strtolower($matches[1])] ?? null;
        } elseif (str_contains($normalized, 'первый квартал')) {
            $quarter = 1;
        } elseif (str_contains($normalized, 'второй квартал')) {
            $quarter = 2;
        } elseif (str_contains($normalized, 'третий квартал')) {
            $quarter = 3;
        } elseif (str_contains($normalized, 'четвертый квартал') || str_contains($normalized, 'четвёртый квартал')) {
            $quarter = 4;
        }

        if ($year === null || $quarter === null) {
            return null;
        }

        return EstimatePricePeriod::query()
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->first();
    }

    private function contextFromVersion(EstimateRegionalPriceVersion $version, string $source): array
    {
        return [
            'estimate_regional_price_version_id' => $version->id,
            'region_id' => $version->region_id,
            'region_name' => $version->region?->name,
            'price_zone_id' => $version->price_zone_id,
            'price_zone_name' => $version->priceZone?->name,
            'period_id' => $version->period_id,
            'period_name' => $version->period?->name,
            'year' => $version->period?->year,
            'quarter' => $version->period?->quarter,
            'version_key' => $version->version_key,
            'source' => $source,
            'status' => $version->status instanceof RegionalPriceStatus ? $version->status->value : (string) $version->status,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function inputText(array $input): string
    {
        return trim(implode("\n", array_filter([
            $this->nullableString($input['description'] ?? null),
            $this->nullableString($input['region'] ?? null),
        ])));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
