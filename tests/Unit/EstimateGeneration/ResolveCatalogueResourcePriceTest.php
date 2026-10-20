<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativeResourcePriceTarget;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCatalogueResourcePrice;
use Tests\TestCase;

final class ResolveCatalogueResourcePriceTest extends TestCase
{
    public function test_an_included_quote_keeps_its_tax_evidence_and_produces_a_net_material_cost_without_regional_ids(): void
    {
        $resource = $this->resource();
        $snapshot = (new ResolveCatalogueResourcePrice)->handle($resource)->toArray();
        self::assertSame('100.0000', $snapshot['base_amount']);
        self::assertSame('234.50', $snapshot['final_amount']);
        self::assertSame('catalog_resource', $snapshot['source_type']);
        self::assertSame('catalog:87', $snapshot['source_reference']);
        foreach (['region_id', 'zone_id', 'period_id', 'version_id'] as $key) {
            self::assertSame(0, $snapshot[$key]);
        }
        self::assertSame('119.00', $snapshot['coefficients']['catalog_source']['unit_price']);
        self::assertSame('19', $snapshot['coefficients']['catalog_source']['vat_rate']);
        self::assertTrue($snapshot['coefficients']['vat_removed_from_included_quote']);
        self::assertSame($this->resource(), $resource);
    }

    public function test_a_quote_without_vat_does_not_gain_an_invented_tax_rate(): void
    {
        $resource = $this->resource();
        $resource['normative_ref']['catalog_price_selection']['source_snapshot']['vat_mode'] = 'not_applicable';
        $resource['normative_ref']['catalog_price_selection']['source_snapshot']['vat_rate'] = null;
        $snapshot = (new ResolveCatalogueResourcePrice)->handle($resource)->toArray();
        self::assertSame('119.0000', $snapshot['base_amount']);
        self::assertSame('279.06', $snapshot['final_amount']);
        self::assertFalse($snapshot['coefficients']['vat_removed_from_included_quote']);
        self::assertNull($snapshot['coefficients']['catalog_source']['vat_rate']);
    }

    public function test_unknown_volume_cannot_be_priced_as_zero(): void
    {
        $resource = $this->resource();
        $resource['quantity'] = null;
        $this->expectException(MissingRegionalPrice::class);
        (new ResolveCatalogueResourcePrice)->handle($resource);
    }

    public function test_missing_delivery_is_not_silently_included_in_the_quote(): void
    {
        $resource = $this->resource();
        $resource['normative_ref']['catalog_price_selection']['source_snapshot']['delivery_included'] = false;
        $this->expectException(MissingRegionalPrice::class);
        (new ResolveCatalogueResourcePrice)->handle($resource);
    }

    private function resource(): array
    {
        return ['unit' => 'm2', 'quantity' => '2.345', 'normative_ref' => ['catalog_price_selection' => [
            'contract_version' => NormativeResourcePriceTarget::CONTRACT, 'same_product_confirmed' => true,
            'fact_id' => 'fact:choice', 'decision_id' => 'decision:choice', 'target_hash' => str_repeat('a', 64),
            'product_confirmation_basis' => 'Сохранённое подтверждение соответствия материала.',
            'source_snapshot' => ['source_reference' => 'catalog:87', 'source_hash' => str_repeat('b', 64),
                'source_hash_contract' => 'catalogue-material-price:v1', 'item_type' => 'material',
                'verified' => true, 'applicable' => true, 'conditions_missing' => [], 'currency' => 'RUB',
                'unit' => 'm2', 'unit_price' => '119.00', 'vat_mode' => 'included', 'vat_rate' => '19', 'delivery_included' => true],
        ]]];
    }
}
