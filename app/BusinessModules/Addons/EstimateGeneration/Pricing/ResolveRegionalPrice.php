<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialAbstractResourceConversionCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;

class ResolveRegionalPrice
{
    public function __construct(
        private readonly ?Closure $lookup = null,
        private readonly ResidentialAbstractResourceConversionCatalog $residentialConversions = new ResidentialAbstractResourceConversionCatalog,
    ) {}

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
            throw MissingRegionalPrice::forResource($priceId ?? 0, 'pricing_context_incomplete');
        }

        $price = $this->lookup !== null ? ($this->lookup)($priceId) : $this->find($priceId);
        $payload = $this->payload($price);
        $regionalPrice = $payload !== null && $this->matchesRegionalContext($payload, $regionId, $zoneId, $periodId, $versionId);
        $baseCatalogPrice = $payload !== null && $this->isApprovedBaseCatalogPrice($payload);
        if ($payload === null || (! $regionalPrice && ! $baseCatalogPrice) || ! $this->hasPositivePrice($payload)) {
            throw MissingRegionalPrice::forResource($priceId, 'price_payload_unusable');
        }

        $baseAmount = BigDecimal::of((string) $payload['base_price']);
        $coefficients = ['quantity' => $this->decimal($resource['quantity'] ?? 0, 6)];
        if ($baseCatalogPrice) {
            $coefficients['price_kind'] = 'base_catalog';
            $coefficients['dataset_version_id'] = (int) $payload['dataset_version_id'];
        }
        $conversion = $baseCatalogPrice ? $this->residentialConversion($resource, $payload) : null;
        if ($conversion !== null) {
            $baseAmount = $baseAmount->multipliedBy($conversion['factor']);
            $coefficients = [
                ...$coefficients,
                'price_kind' => 'base_catalog_converted',
                'source_unit_price' => $this->decimal($payload['base_price'], 4),
                'source_price_unit' => $conversion['from_unit'],
                'conversion_factor' => $conversion['factor'],
                'conversion_assumption' => $conversion['assumption'],
                'selected_resource_code' => $conversion['selected_resource_code'],
            ];
        }

        return new PriceSnapshotData(
            regionId: $regionId,
            zoneId: $zoneId,
            periodId: $periodId,
            versionId: $versionId,
            sourceType: (string) ($payload['source_type'] ?? 'regional_catalog'),
            sourceReference: 'estimate_resource_prices:'.$priceId,
            baseAmount: (string) $baseAmount->toScale(4, RoundingMode::HalfUp),
            coefficients: $coefficients,
            finalAmount: (string) $baseAmount
                ->multipliedBy(BigDecimal::of((string) ($resource['quantity'] ?? '0')))
                ->toScale(2, RoundingMode::HalfUp),
            currency: (string) ($payload['currency'] ?? 'RUB'),
            capturedAt: now()->toIso8601String(),
        );
    }

    private function residentialConversion(array $resource, array $payload): ?array
    {
        $reference = is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : [];
        $referenceSelection = is_array($reference['project_resource_selection'] ?? null)
            ? $reference['project_resource_selection']
            : null;
        $selection = is_array($resource['project_resource_selection'] ?? null)
            ? $resource['project_resource_selection']
            : $referenceSelection;
        if ($selection === null || ! str_contains((string) ($selection['policy'] ?? ''), '_residential_converted_')) {
            return null;
        }
        if ($referenceSelection === null
            || ($selection['group_code'] ?? null) !== ($referenceSelection['group_code'] ?? null)
            || ($selection['selected_resource_code'] ?? null) !== ($referenceSelection['selected_resource_code'] ?? null)
            || ($selection['policy'] ?? null) !== ($referenceSelection['policy'] ?? null)) {
            throw MissingRegionalPrice::forResource(
                $this->positiveInt($resource['price_id'] ?? $reference['price_id'] ?? null) ?? 0,
                'residential_selection_identity_mismatch',
            );
        }
        $normCode = trim((string) ($reference['norm_code'] ?? ''));
        $groupCode = trim((string) ($reference['resource_code'] ?? ''));
        $conversion = $this->residentialConversions->find($normCode, $groupCode);
        if ($conversion === null) {
            throw MissingRegionalPrice::forResource(
                $this->positiveInt($resource['price_id'] ?? $reference['price_id'] ?? null) ?? 0,
                'residential_conversion_not_supported',
            );
        }
        $sourceType = (string) ($payload['source_type'] ?? '');
        $expectedPolicy = $sourceType.'_residential_converted_child_median:v1';
        $expectedPriceSource = match ($sourceType) {
            'fsbc' => 'fsbc_base',
            'fsnb_2022' => 'fsnb_base',
            default => null,
        };
        $selectedResourceCode = trim((string) ($selection['selected_resource_code'] ?? ''));
        $datasetVersion = trim((string) ($payload['dataset_version'] ?? ''));
        try {
            $sourcePriceMatches = BigDecimal::of((string) ($selection['source_unit_price'] ?? '0'))
                ->isEqualTo(BigDecimal::of((string) ($payload['base_price'] ?? '0')));
            $factorMatches = BigDecimal::of((string) ($selection['conversion_factor'] ?? '0'))
                ->isEqualTo(BigDecimal::of($conversion['factor']));
            $appliedPriceMatches = BigDecimal::of((string) ($resource['unit_price'] ?? '0'))
                ->isEqualTo(BigDecimal::of((string) $payload['base_price'])
                    ->multipliedBy($conversion['factor'])
                    ->toScale(6, RoundingMode::HalfUp));
        } catch (\Throwable) {
            throw MissingRegionalPrice::forResource(
                $this->positiveInt($resource['price_id'] ?? $reference['price_id'] ?? null) ?? 0,
                'residential_conversion_decimal_invalid',
            );
        }
        if (($selection['group_code'] ?? null) !== $groupCode
            || ($selection['policy'] ?? null) !== $expectedPolicy
            || ($selection['price_source'] ?? null) !== $expectedPriceSource
            || trim((string) ($selection['price_source_version'] ?? '')) !== $datasetVersion
            || $selectedResourceCode !== trim((string) ($payload['resource_code'] ?? ''))
            || preg_match('/^'.preg_quote($groupCode, '/').'-\d{4}$/D', $selectedResourceCode) !== 1
            || trim((string) ($selection['source_price_unit'] ?? '')) !== $conversion['from_unit']
            || trim((string) ($payload['unit'] ?? '')) !== $conversion['from_unit']
            || ! NormativeUnitNormalizer::compatible((string) ($resource['price_unit'] ?? ''), $conversion['to_unit'])
            || ! NormativeUnitNormalizer::compatible((string) ($resource['unit'] ?? ''), $conversion['to_unit'])
            || trim((string) ($selection['conversion_assumption'] ?? '')) !== $conversion['assumption']
            || ! $sourcePriceMatches || ! $factorMatches || ! $appliedPriceMatches) {
            throw MissingRegionalPrice::forResource(
                $this->positiveInt($resource['price_id'] ?? $reference['price_id'] ?? null) ?? 0,
                'residential_conversion_provenance_mismatch',
            );
        }

        return [
            ...$conversion,
            'selected_resource_code' => $selectedResourceCode,
        ];
    }

    /** @param array<string, mixed> $embeddedPrice */
    private function embeddedCatalogPrice(array $resource, array $regionalContext, array $embeddedPrice): PriceSnapshotData
    {
        $sourceType = (string) ($embeddedPrice['source_type'] ?? '');
        $rateId = $this->positiveInt($embeddedPrice['normative_rate_id'] ?? null);
        $resourceId = $this->positiveInt($embeddedPrice['normative_rate_resource_id'] ?? null);
        $baseAmount = BigDecimal::of((string) ($embeddedPrice['base_amount'] ?? '0'));

        if ($sourceType !== 'normative_rate_base' || $rateId === null || $baseAmount->isLessThanOrEqualTo(0)) {
            throw MissingRegionalPrice::forResource(0, 'embedded_price_invalid');
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
            'dataset_version' => $price->datasetVersion?->version_key,
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
