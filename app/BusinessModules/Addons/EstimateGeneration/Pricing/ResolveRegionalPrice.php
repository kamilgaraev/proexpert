<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;

class ResolveRegionalPrice
{
    public function __construct(private readonly ?Closure $lookup = null) {}

    public function handle(array $resource, array $regionalContext): PriceSnapshotData
    {
        $priceId = $this->positiveInt($resource['price_id'] ?? null);
        $regionId = $this->positiveInt($regionalContext['region_id'] ?? null);
        $zoneId = $this->positiveInt($regionalContext['price_zone_id'] ?? null);
        $periodId = $this->positiveInt($regionalContext['period_id'] ?? null);
        $versionId = $this->positiveInt($regionalContext['estimate_regional_price_version_id'] ?? $regionalContext['version_id'] ?? null);

        if ($priceId === null || $regionId === null || $zoneId === null || $periodId === null || $versionId === null) {
            throw MissingRegionalPrice::forResource($priceId ?? 0);
        }

        $price = $this->lookup !== null ? ($this->lookup)($priceId) : $this->find($priceId, $regionId, $zoneId, $periodId, $versionId);
        $payload = $this->payload($price);
        if ($payload === null
            || (int) ($payload['region_id'] ?? 0) !== $regionId
            || (int) ($payload['price_zone_id'] ?? 0) !== $zoneId
            || (int) ($payload['period_id'] ?? 0) !== $periodId
            || (int) ($payload['regional_price_version_id'] ?? 0) !== $versionId) {
            throw MissingRegionalPrice::forResource($priceId);
        }

        return new PriceSnapshotData(
            regionId: $regionId,
            zoneId: $zoneId,
            periodId: $periodId,
            versionId: $versionId,
            sourceType: (string) ($payload['source_type'] ?? 'regional_catalog'),
            sourceReference: 'estimate_resource_prices:'.$priceId,
            baseAmount: $this->decimal($payload['base_price'] ?? 0, 4),
            coefficients: ['quantity' => $this->decimal($resource['quantity'] ?? 0, 6)],
            finalAmount: (string) BigDecimal::of((string) ($payload['base_price'] ?? '0'))
                ->multipliedBy(BigDecimal::of((string) ($resource['quantity'] ?? '0')))
                ->toScale(2, RoundingMode::HalfUp),
            currency: (string) ($payload['currency'] ?? 'RUB'),
            capturedAt: now()->toIso8601String(),
        );
    }

    private function find(int $priceId, int $regionId, int $zoneId, int $periodId, int $versionId): ?EstimateResourcePrice
    {
        return EstimateResourcePrice::query()
            ->with('datasetVersion')
            ->whereKey($priceId)
            ->where('region_id', $regionId)
            ->where('price_zone_id', $zoneId)
            ->where('period_id', $periodId)
            ->where('regional_price_version_id', $versionId)
            ->first();
    }

    private function payload(mixed $price): ?array
    {
        if (is_array($price)) {
            return $price;
        }
        if (! $price instanceof EstimateResourcePrice) {
            return null;
        }

        return [
            ...$price->getAttributes(),
            'source_type' => $price->datasetVersion?->source_type?->value ?? 'regional_catalog',
            'currency' => is_array($price->raw_payload) ? ($price->raw_payload['currency'] ?? 'RUB') : 'RUB',
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp);
    }
}
