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
        $embeddedPrice = $resource['normative_ref']['embedded_price'] ?? null;
        if (is_array($embeddedPrice)) {
            return $this->embeddedCatalogPrice($resource, $regionalContext, $embeddedPrice);
        }

        $priceId = $this->positiveInt($resource['price_id'] ?? $resource['normative_ref']['price_id'] ?? null);
        $regionId = $this->positiveInt($regionalContext['region_id'] ?? null);
        $zoneId = $this->positiveInt($regionalContext['price_zone_id'] ?? null);
        $periodId = $this->positiveInt($regionalContext['period_id'] ?? null);
        $versionId = $this->positiveInt($regionalContext['estimate_regional_price_version_id'] ?? $regionalContext['version_id'] ?? null);

        if ($priceId === null || $regionId === null || $zoneId === null || $periodId === null || $versionId === null) {
            throw MissingRegionalPrice::forResource($priceId ?? 0);
        }

        $price = $this->lookup !== null ? ($this->lookup)($priceId) : $this->find($priceId);
        $payload = $this->payload($price);
        $regionalPrice = $payload !== null && $this->matchesRegionalContext($payload, $regionId, $zoneId, $periodId, $versionId);
        $baseCatalogPrice = $payload !== null && $this->isApprovedBaseCatalogPrice($payload);
        if ($payload === null || (! $regionalPrice && ! $baseCatalogPrice) || ! $this->hasPositivePrice($payload)) {
            throw MissingRegionalPrice::forResource($priceId);
        }

        $coefficients = ['quantity' => $this->decimal($resource['quantity'] ?? 0, 6)];
        if ($baseCatalogPrice) {
            $coefficients['price_kind'] = 'base_catalog';
            $coefficients['dataset_version_id'] = (int) $payload['dataset_version_id'];
        }

        return new PriceSnapshotData(
            regionId: $regionId,
            zoneId: $zoneId,
            periodId: $periodId,
            versionId: $versionId,
            sourceType: (string) ($payload['source_type'] ?? 'regional_catalog'),
            sourceReference: 'estimate_resource_prices:'.$priceId,
            baseAmount: $this->decimal($payload['base_price'] ?? 0, 4),
            coefficients: $coefficients,
            finalAmount: (string) BigDecimal::of((string) ($payload['base_price'] ?? '0'))
                ->multipliedBy(BigDecimal::of((string) ($resource['quantity'] ?? '0')))
                ->toScale(2, RoundingMode::HalfUp),
            currency: (string) ($payload['currency'] ?? 'RUB'),
            capturedAt: now()->toIso8601String(),
        );
    }

    /** @param array<string, mixed> $embeddedPrice */
    private function embeddedCatalogPrice(array $resource, array $regionalContext, array $embeddedPrice): PriceSnapshotData
    {
        $sourceType = (string) ($embeddedPrice['source_type'] ?? '');
        $rateId = $this->positiveInt($embeddedPrice['normative_rate_id'] ?? null);
        $resourceId = $this->positiveInt($embeddedPrice['normative_rate_resource_id'] ?? null);
        $baseAmount = BigDecimal::of((string) ($embeddedPrice['base_amount'] ?? '0'));

        if ($sourceType !== 'normative_rate_base' || $rateId === null || $baseAmount->isLessThanOrEqualTo(0)) {
            throw MissingRegionalPrice::forResource(0);
        }

        $quantity = BigDecimal::of((string) ($resource['quantity'] ?? '0'));

        return new PriceSnapshotData(
            regionId: $this->positiveInt($regionalContext['region_id'] ?? null) ?? 0,
            zoneId: $this->positiveInt($regionalContext['price_zone_id'] ?? null) ?? 0,
            periodId: $this->positiveInt($regionalContext['period_id'] ?? null) ?? 0,
            versionId: $rateId,
            sourceType: $sourceType,
            sourceReference: $resourceId !== null
                ? 'normative_rate_resources:'.$resourceId
                : 'normative_rates:'.$rateId,
            baseAmount: (string) $baseAmount->toScale(4, RoundingMode::HalfUp),
            coefficients: [
                'quantity' => (string) $quantity->toScale(6, RoundingMode::HalfUp),
                'base_year' => $embeddedPrice['base_year'] ?? null,
            ],
            finalAmount: (string) $baseAmount
                ->multipliedBy($quantity)
                ->toScale(2, RoundingMode::HalfUp),
            currency: (string) ($embeddedPrice['currency'] ?? 'RUB'),
            capturedAt: now()->toIso8601String(),
        );
    }

    private function find(int $priceId): ?EstimateResourcePrice
    {
        return EstimateResourcePrice::query()
            ->with('datasetVersion')
            ->whereKey($priceId)
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
            'dataset_status' => $price->datasetVersion?->status?->value ?? $price->datasetVersion?->status,
            'currency' => is_array($price->raw_payload) ? ($price->raw_payload['currency'] ?? 'RUB') : 'RUB',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function matchesRegionalContext(array $payload, int $regionId, int $zoneId, int $periodId, int $versionId): bool
    {
        return (int) ($payload['region_id'] ?? 0) === $regionId
            && (int) ($payload['price_zone_id'] ?? 0) === $zoneId
            && (int) ($payload['period_id'] ?? 0) === $periodId
            && (int) ($payload['regional_price_version_id'] ?? 0) === $versionId;
    }

    /** @param array<string, mixed> $payload */
    private function isApprovedBaseCatalogPrice(array $payload): bool
    {
        return $this->positiveInt($payload['dataset_version_id'] ?? null) !== null
            && ($payload['dataset_status'] ?? null) === 'parsed'
            && in_array($payload['source_type'] ?? null, ['fsbc', 'fsnb_2022'], true)
            && ($payload['regional_price_version_id'] ?? null) === null
            && ($payload['region_id'] ?? null) === null
            && ($payload['price_zone_id'] ?? null) === null
            && ($payload['period_id'] ?? null) === null;
    }

    /** @param array<string, mixed> $payload */
    private function hasPositivePrice(array $payload): bool
    {
        try {
            return BigDecimal::of((string) ($payload['base_price'] ?? '0'))->isGreaterThan(0);
        } catch (\Throwable) {
            return false;
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $normalizedMaximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($normalizedMaximum)
            || (strlen($value) === strlen($normalizedMaximum) && strcmp($value, $normalizedMaximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp);
    }
}
