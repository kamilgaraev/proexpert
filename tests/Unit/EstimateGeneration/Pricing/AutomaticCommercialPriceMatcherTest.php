<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\AutomaticCommercialPriceMatcher;
use PHPUnit\Framework\TestCase;

final class AutomaticCommercialPriceMatcherTest extends TestCase
{
    public function test_exact_unique_rate_is_selected_with_unknown_quantity_and_no_fabricated_price_choice(): void
    {
        $item = $this->item();
        $price = (new AutomaticCommercialPriceMatcher)->select([$this->price()], $item, 'Москва');
        self::assertSame('10', $price['unit_price']);
        self::assertSame('automatic_exact_name', $price['selection_mode']);
        self::assertNull($item['quantity']);
        self::assertArrayNotHasKey('fact_id', $price);
        self::assertTrue((new AutomaticCommercialPriceMatcher)->frozenBasisMatches($price, $item, '  МОСКВА  '));
        self::assertFalse((new AutomaticCommercialPriceMatcher)->frozenBasisMatches($price, [...$item, 'name' => 'Другая работа'], 'Москва'));
        self::assertFalse((new AutomaticCommercialPriceMatcher)->frozenBasisMatches($price, $item, 'Казань'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatiblePrices')]
    public function test_incompatible_or_unverified_sources_are_not_chosen(array $changed): void
    {
        self::assertNull((new AutomaticCommercialPriceMatcher)->select([[...$this->price(), ...$changed]], $this->item(), 'Москва'));
    }

    public static function incompatiblePrices(): array
    {
        return [[['name' => 'Покраска потолка']], [['unit' => 'm3']], [['item_type' => 'equipment']], [['verified' => false]],
            [['applicable' => false]], [['stale' => true]], [['conditions_missing' => ['region']]], [['technology_package_id' => 'paint:acrylic']],
            [['region' => 'Московская область']], [['region' => null]], [['region' => ['private' => 'Москва']]]];
    }

    public function test_ambiguity_remains_for_manual_selection_even_when_prices_match(): void
    {
        self::assertNull((new AutomaticCommercialPriceMatcher)->select([$this->price(), [...$this->price(), 'source_reference' => 'catalog:2']], $this->item(), 'Москва'));
    }

    public function test_unknown_target_region_never_uses_a_catalog_price_automatically(): void
    {
        self::assertNull((new AutomaticCommercialPriceMatcher)->select([$this->price()], $this->item()));
        self::assertNull((new AutomaticCommercialPriceMatcher)->select([$this->price()], $this->item(), ''));
    }

    private function item(): array
    {
        return ['key' => 'finishing:paint', 'name' => '  ПОКРАСКА   стен ', 'unit' => 'm2', 'quantity' => null];
    }

    private function price(): array
    {
        return ['name' => 'Покраска стен', 'unit' => 'm2', 'item_type' => 'work', 'unit_price' => '10', 'verified' => true,
            'applicable' => true, 'stale' => false, 'conditions_missing' => [], 'source_reference' => 'catalog:1', 'region' => 'Москва'];
    }
}
