<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsPricePeriodDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

class FgiscsRegionalCatalogService
{
    public const DEFAULT_REGION_CODE = 'RU-TA';
    public const TATARSTAN_SUBJECT_ID = 296;
    public const TATARSTAN_PRICE_ZONE_ID = 202;

    public function __construct(
        private readonly FgiscsClient $client,
    ) {
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    public function countrySubjects(): array
    {
        return $this->client->countrySubjects();
    }

    /**
     * @return array{region:EstimateRegion,price_zones:Collection<int, EstimatePriceZone>}
     */
    public function syncSubject(int $subjectId, ?string $regionCode = null, ?string $regionName = null, bool $supported = true): array
    {
        $subject = $regionName !== null
            ? ['id' => $subjectId, 'name' => $regionName]
            : $this->findSubject($subjectId);

        $zones = $this->client->priceZones($subjectId);

        if ($zones === []) {
            throw new RuntimeException('ФГИС ЦС не вернул ценовые зоны региона.');
        }

        $region = EstimateRegion::query()->updateOrCreate(
            ['fgiscs_subject_id' => $subjectId],
            [
                'code' => $regionCode ?? $this->regionCode($subjectId),
                'name' => $subject['name'],
                'is_supported' => $supported,
            ]
        );

        $priceZones = collect($zones)->map(fn (array $zone): EstimatePriceZone => EstimatePriceZone::query()->updateOrCreate(
            ['fgiscs_price_zone_id' => (int) $zone['id']],
            [
                'estimate_region_id' => $region->id,
                'name' => (string) $zone['name'],
            ]
        ));

        return ['region' => $region, 'price_zones' => $priceZones];
    }

    /**
     * @return array{region:EstimateRegion,price_zone:EstimatePriceZone}
     */
    public function syncTatarstan(): array
    {
        $catalog = $this->syncSubject(
            self::TATARSTAN_SUBJECT_ID,
            self::DEFAULT_REGION_CODE,
            'Республика Татарстан',
            true,
        );

        $priceZone = $catalog['price_zones']->firstWhere('fgiscs_price_zone_id', self::TATARSTAN_PRICE_ZONE_ID);

        if (!$priceZone instanceof EstimatePriceZone) {
            throw new RuntimeException('ФГИС ЦС не вернул ценовую зону Татарстана.');
        }

        return ['region' => $catalog['region'], 'price_zone' => $priceZone];
    }

    /**
     * @return Collection<int, EstimateRegion>
     */
    public function supportedRegions(): Collection
    {
        return EstimateRegion::query()
            ->where('is_supported', true)
            ->with('priceZones')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, EstimatePricePeriod>
     */
    public function syncPeriods(int $priceZoneId): array
    {
        return array_map(
            fn (FgiscsPricePeriodDTO $period): EstimatePricePeriod => $this->upsertPeriod($period),
            $this->client->periods($priceZoneId)
        );
    }

    public function latestPeriod(int $priceZoneId): EstimatePricePeriod
    {
        $periods = $this->syncPeriods($priceZoneId);

        usort($periods, static fn (EstimatePricePeriod $left, EstimatePricePeriod $right): int => [
            $right->year,
            $right->quarter,
        ] <=> [
            $left->year,
            $left->quarter,
        ]);

        return $periods[0] ?? throw new RuntimeException('ФГИС ЦС не вернул периоды цен региона.');
    }

    /**
     * @return array<int, EstimatePricePeriod>
     */
    public function allPeriods(int $priceZoneId): array
    {
        $periods = $this->syncPeriods($priceZoneId);

        usort($periods, static fn (EstimatePricePeriod $left, EstimatePricePeriod $right): int => [
            $right->year,
            $right->quarter,
        ] <=> [
            $left->year,
            $left->quarter,
        ]);

        return $periods;
    }

    public function regionCode(int $subjectId): string
    {
        return $subjectId === self::TATARSTAN_SUBJECT_ID
            ? self::DEFAULT_REGION_CODE
            : 'FGISCS-' . $subjectId;
    }

    /**
     * @return array{id:int,name:string}
     */
    private function findSubject(int $subjectId): array
    {
        foreach ($this->client->countrySubjects() as $subject) {
            if ((int) $subject['id'] === $subjectId) {
                return $subject;
            }
        }

        throw new RuntimeException('Регион ФГИС ЦС не найден.');
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
