<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsPricePeriodDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use Carbon\CarbonImmutable;
use RuntimeException;

class FgiscsTatarstanCatalogService
{
    public const REGION_CODE = 'RU-TA';
    public const SUBJECT_ID = 296;
    public const PRICE_ZONE_ID = 202;

    public function __construct(
        private readonly FgiscsClient $client,
    ) {
    }

    /**
     * @return array{region:EstimateRegion,price_zone:EstimatePriceZone}
     */
    public function syncRegionAndZone(): array
    {
        $zones = $this->client->priceZones(self::SUBJECT_ID);
        $zone = collect($zones)->firstWhere('id', self::PRICE_ZONE_ID);

        if ($zone === null) {
            throw new RuntimeException('ФГИС ЦС не вернул ценовую зону Татарстана.');
        }

        $region = EstimateRegion::query()->updateOrCreate(
            ['code' => self::REGION_CODE],
            [
                'name' => 'Республика Татарстан',
                'fgiscs_subject_id' => self::SUBJECT_ID,
                'is_supported' => true,
            ]
        );

        $priceZone = EstimatePriceZone::query()->updateOrCreate(
            ['fgiscs_price_zone_id' => self::PRICE_ZONE_ID],
            [
                'estimate_region_id' => $region->id,
                'name' => (string) $zone['name'],
            ]
        );

        return ['region' => $region, 'price_zone' => $priceZone];
    }

    /**
     * @return array<int, EstimatePricePeriod>
     */
    public function syncPeriods(): array
    {
        return array_map(
            fn (FgiscsPricePeriodDTO $period): EstimatePricePeriod => $this->upsertPeriod($period),
            $this->client->periods(self::PRICE_ZONE_ID)
        );
    }

    public function latestPeriod(): EstimatePricePeriod
    {
        $periods = $this->syncPeriods();

        usort($periods, static fn (EstimatePricePeriod $left, EstimatePricePeriod $right): int => [
            $right->year,
            $right->quarter,
        ] <=> [
            $left->year,
            $left->quarter,
        ]);

        return $periods[0] ?? throw new RuntimeException('ФГИС ЦС не вернул периоды цен Татарстана.');
    }

    private function upsertPeriod(FgiscsPricePeriodDTO $period): EstimatePricePeriod
    {
        $startsAt = CarbonImmutable::create($period->year, (($period->quarter - 1) * 3) + 1, 1);

        return EstimatePricePeriod::query()->updateOrCreate(
            ['fgiscs_period_id' => $period->id],
            [
                'name' => $period->name,
                'year' => $period->year,
                'quarter' => $period->quarter,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => $startsAt->addMonths(3)->subDay()->toDateString(),
            ]
        );
    }
}
