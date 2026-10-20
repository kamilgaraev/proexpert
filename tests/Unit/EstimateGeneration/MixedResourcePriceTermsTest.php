<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\MixedResourcePriceTerms;
use PHPUnit\Framework\TestCase;

final class MixedResourcePriceTermsTest extends TestCase
{
    public function test_material_quotes_keep_their_own_source_dates_and_tax_basis_in_a_composite_normative_price(): void
    {
        [$price, $regional, $terms] = $this->fixture();
        $result = (new MixedResourcePriceTerms)->resolve($price, $regional, $terms);
        self::assertNotNull($result);
        self::assertSame('2025-03-14', $result['as_of_date']);
        self::assertSame(47, $result['price_version_id']);
        self::assertSame('22', $result['vat_rate']);
        self::assertSame('excluded', $result['vat_mode']);
        self::assertSame('normative_component', $result['region_scope']);
        self::assertTrue($result['catalog_resources_net_of_vat']);
        self::assertSame([], $result['conditions_missing']);
        self::assertSame($this->fixture()[0], $price);
    }

    public function test_a_base_year_resource_cannot_inherit_a_current_regional_price_level(): void
    {
        [$price, $regional, $terms] = $this->fixture();
        $price['coefficients']['resource_evidence'][0]['source_type'] = 'fsnb_base';
        self::assertNull((new MixedResourcePriceTerms)->resolve($price, $regional, $terms));
    }

    public function test_unknown_regional_tax_or_unpriced_delivery_blocks_the_composite_price(): void
    {
        [$price, $regional, $terms] = $this->fixture();
        self::assertNull((new MixedResourcePriceTerms)->resolve($price, $regional, [...$terms, 'vat_rate' => null]));
        $price['coefficients']['resource_evidence'][1]['coefficients']['catalog_source']['delivery_included'] = false;
        self::assertNull((new MixedResourcePriceTerms)->resolve($price, $regional, $terms));
    }

    public function test_stale_material_prices_require_their_own_acceptance(): void
    {
        [$price, $regional, $terms] = $this->fixture();
        $price['coefficients']['resource_evidence'][1]['coefficients']['catalog_source']['stale'] = true;
        self::assertNull((new MixedResourcePriceTerms)->resolve($price, $regional, $terms));
        $price['coefficients']['resource_evidence'][1]['coefficients']['catalog_source']['stale_accepted'] = true;
        $accepted = (new MixedResourcePriceTerms)->resolve($price, $regional, $terms);
        self::assertTrue($accepted['stale']);
        self::assertTrue($accepted['stale_accepted']);
    }

    private function fixture(): array
    {
        $regional = ['region_id' => 17, 'price_zone_id' => 23, 'period_id' => 31, 'estimate_regional_price_version_id' => 47];
        $terms = ['estimate_regional_price_version_id' => 47, 'vat_mode' => 'excluded', 'vat_rate' => '22',
            'delivery' => 'included', 'as_of_date' => '2026-01-02', 'basis' => 'Подтверждённые условия региональной части.', 'decision_id' => 'decision:terms'];
        $price = ['source_type' => 'mixed_resource_aggregate', 'coefficients' => ['resource_evidence' => [
            ['source_type' => 'fgis_labor_prices', 'source_reference' => 'estimate_resource_prices:67',
                'currency' => 'RUB', 'region_id' => 17, 'zone_id' => 23, 'period_id' => 31, 'version_id' => 47],
            ['source_type' => 'catalog_resource', 'source_reference' => 'catalog:83', 'currency' => 'RUB', 'coefficients' => [
                'resource_choice_fact_id' => 'fact:material-price', 'resource_choice_decision_id' => 'decision:material-price',
                'same_product_confirmed' => true, 'product_confirmation_basis' => 'Подтверждено соответствие материала.',
                'net_unit_price_scale' => 4, 'vat_removed_from_included_quote' => true,
                'catalog_source' => ['source_reference' => 'catalog:83', 'source_hash' => str_repeat('a', 64),
                    'source_hash_contract' => 'catalogue-material-price:v1', 'item_type' => 'material',
                    'verified' => true, 'applicable' => true, 'currency' => 'RUB', 'conditions_missing' => [],
                    'delivery_included' => true, 'as_of_date' => '2025-03-14', 'vat_mode' => 'included'],
            ]],
        ]]];

        return [$price, $regional, $terms];
    }
}
