<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

final class MixedResourcePriceTerms
{
    public function resolve(array $price, array $regional, ?array $terms): ?array
    {
        $resources = $price['coefficients']['resource_evidence'] ?? null;
        if (($price['source_type'] ?? null) !== 'mixed_resource_aggregate' || ! is_array($resources) || ! array_is_list($resources) || $resources === []
            || $terms === null || ! in_array($terms['vat_mode'] ?? null, ['excluded', 'not_applicable'], true)
            || ! in_array($terms['delivery'] ?? null, ['included', 'outside_scope'], true)
            || ! $this->date($terms['as_of_date'] ?? null) || ! is_string($terms['basis'] ?? null) || trim($terms['basis']) === ''
            || ! is_string($terms['decision_id'] ?? null)
            || (int) ($terms['estimate_regional_price_version_id'] ?? 0) !== (int) ($regional['estimate_regional_price_version_id'] ?? -1)) {
            return null;
        }
        if ($terms['vat_mode'] === 'excluded' && ((! is_string($terms['vat_rate'] ?? null) && ! is_int($terms['vat_rate'] ?? null))
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', (string) $terms['vat_rate']) !== 1
            || \Brick\Math\BigDecimal::of((string) $terms['vat_rate'])->isGreaterThan(100))) {
            return null;
        }
        $dates = [$terms['as_of_date']];
        $catalogCount = $nativeCount = 0;
        $stale = false;
        foreach ($resources as $resource) {
            if (! is_array($resource) || ($resource['currency'] ?? null) !== 'RUB') {
                return null;
            }
            if (($resource['source_type'] ?? null) === 'catalog_resource') {
                $catalogCount++;
                $source = $resource['coefficients']['catalog_source'] ?? null;
                if (! is_array($source) || ($source['verified'] ?? null) !== true || ($source['applicable'] ?? null) !== true
                    || ($source['source_hash_contract'] ?? null) !== 'catalogue-material-price:v1' || ($source['item_type'] ?? null) !== 'material'
                    || ($source['currency'] ?? null) !== 'RUB' || ($source['conditions_missing'] ?? []) !== []
                    || ($source['delivery_included'] ?? null) !== true || ! $this->date($source['as_of_date'] ?? null)
                    || ! in_array($source['vat_mode'] ?? null, ['included', 'excluded', 'not_applicable'], true)
                    || ! is_string($resource['source_reference'] ?? null)
                    || preg_match('/\Acatalog:[1-9][0-9]*\z/', $resource['source_reference']) !== 1
                    || ($source['source_reference'] ?? null) !== $resource['source_reference']
                    || ! is_string($source['source_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $source['source_hash']) !== 1
                    || ($resource['coefficients']['same_product_confirmed'] ?? null) !== true
                    || ! is_string($resource['coefficients']['resource_choice_fact_id'] ?? null)
                    || ! is_string($resource['coefficients']['resource_choice_decision_id'] ?? null)
                    || ! is_string($resource['coefficients']['product_confirmation_basis'] ?? null)
                    || trim($resource['coefficients']['product_confirmation_basis']) === ''
                    || ($resource['coefficients']['net_unit_price_scale'] ?? null) !== 4
                    || ($resource['coefficients']['vat_removed_from_included_quote'] ?? null) !== ($source['vat_mode'] === 'included')
                    || (($source['stale'] ?? false) === true && ($source['stale_accepted'] ?? null) !== true)) {
                    return null;
                }
                $stale = $stale || ($source['stale'] ?? false) === true;
                $dates[] = $source['as_of_date'];
            } else {
                $nativeCount++;
                if (! is_string($resource['source_type'] ?? null) || $resource['source_type'] === ''
                    || in_array($resource['source_type'], ['fsnb_base', 'fsbc_base', 'fsnb_2022', 'fsbc', 'normative_rate_base'], true)
                    || str_starts_with((string) ($resource['coefficients']['price_kind'] ?? ''), 'base_catalog')
                    || ! is_string($resource['source_reference'] ?? null)
                    || preg_match('/\Aestimate_resource_prices:[1-9][0-9]*\z/', $resource['source_reference']) !== 1) {
                    return null;
                }
                foreach (['region_id' => 'region_id', 'zone_id' => 'price_zone_id', 'period_id' => 'period_id', 'version_id' => 'estimate_regional_price_version_id'] as $sourceKey => $contextKey) {
                    if ((int) ($resource[$sourceKey] ?? 0) < 1 || (int) $resource[$sourceKey] !== (int) ($regional[$contextKey] ?? 0)) {
                        return null;
                    }
                }
            }
        }
        if ($catalogCount === 0 || $nativeCount === 0) {
            return null;
        }
        sort($dates, SORT_STRING);

        return ['as_of_date' => $dates[0], 'level' => 'current', 'region_id' => (int) $regional['region_id'],
            'price_period_id' => (int) $regional['period_id'], 'price_version_id' => (int) $regional['estimate_regional_price_version_id'],
            'vat_mode' => $terms['vat_mode'], 'vat_rate' => $terms['vat_rate'] ?? null,
            'delivery_included' => $terms['delivery'] === 'included', 'delivery_outside_scope' => $terms['delivery'] === 'outside_scope',
            'conditions_basis' => $terms['basis'], 'conditions_decision_id' => $terms['decision_id'],
            'catalog_resources_net_of_vat' => true, 'region_scope' => 'normative_component',
            'stale' => $stale, 'stale_accepted' => $stale, 'conditions_missing' => []];
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
