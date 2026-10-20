<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativeResourcePriceTarget;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ResolveCatalogueResourcePrice
{
    public function handle(array $resource): PriceSnapshotData
    {
        $selection = $resource['normative_ref']['catalog_price_selection'] ?? null;
        $source = is_array($selection) ? ($selection['source_snapshot'] ?? null) : null;
        if (! is_array($source) || ($selection['contract_version'] ?? null) !== NormativeResourcePriceTarget::CONTRACT
            || ($selection['same_product_confirmed'] ?? null) !== true
            || ! is_string($selection['fact_id'] ?? null) || ! is_string($selection['decision_id'] ?? null)
            || ! is_string($selection['target_hash'] ?? null) || ! is_string($selection['product_confirmation_basis'] ?? null)
            || ! is_string($source['source_reference'] ?? null) || preg_match('/\Acatalog:[1-9][0-9]*\z/', $source['source_reference']) !== 1
            || ($source['source_hash_contract'] ?? null) !== 'catalogue-material-price:v1'
            || ($source['item_type'] ?? null) !== 'material'
            || ($source['verified'] ?? null) !== true || ($source['applicable'] ?? null) !== true
            || ($source['conditions_missing'] ?? []) !== [] || ($source['currency'] ?? null) !== 'RUB'
            || ($source['delivery_included'] ?? null) !== true || ($source['unit'] ?? null) !== ($resource['unit'] ?? null)
            || (($source['stale'] ?? false) === true && ($source['stale_accepted'] ?? null) !== true)
            || ! is_string($source['unit_price'] ?? null) || ! is_string($resource['quantity'] ?? null)) {
            throw MissingRegionalPrice::forResource(0, 'catalog_resource_price_unusable');
        }
        $unitPrice = BigDecimal::of($source['unit_price']);
        $quantity = BigDecimal::of($resource['quantity']);
        $mode = $source['vat_mode'] ?? null;
        if ($unitPrice->isLessThanOrEqualTo(0) || $quantity->isLessThan(0)
            || ! in_array($mode, ['included', 'excluded', 'not_applicable'], true)) {
            throw MissingRegionalPrice::forResource(0, 'catalog_resource_amount_or_tax_invalid');
        }
        $rate = $source['vat_rate'] ?? null;
        if ($mode !== 'not_applicable' && ((! is_string($rate) && ! is_int($rate))
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', (string) $rate) !== 1
            || BigDecimal::of((string) $rate)->isGreaterThan(100))) {
            throw MissingRegionalPrice::forResource(0, 'catalog_resource_tax_unknown');
        }
        $net = $mode === 'included'
            ? $unitPrice->dividedBy(BigDecimal::one()->plus(BigDecimal::of((string) $rate)->withPointMovedLeft(2)), 18, RoundingMode::HalfUp)
            : $unitPrice;
        $net = $net->toScale(4, RoundingMode::HalfUp);
        if ($net->isLessThanOrEqualTo(0)) {
            throw MissingRegionalPrice::forResource(0, 'catalog_resource_net_price_unusable');
        }

        return new PriceSnapshotData(regionId: 0, zoneId: 0, periodId: 0, versionId: 0,
            sourceType: 'catalog_resource', sourceReference: $source['source_reference'], baseAmount: (string) $net,
            coefficients: ['quantity' => (string) $quantity->strippedOfTrailingZeros(), 'catalog_source' => $source,
                'resource_choice_fact_id' => $selection['fact_id'], 'resource_choice_decision_id' => $selection['decision_id'],
                'resource_target_hash' => $selection['target_hash'], 'same_product_confirmed' => true,
                'product_confirmation_basis' => $selection['product_confirmation_basis'],
                'net_unit_price_scale' => 4, 'vat_removed_from_included_quote' => $mode === 'included'],
            finalAmount: (string) $net->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp), currency: 'RUB', capturedAt: now()->toIso8601String());
    }
}
