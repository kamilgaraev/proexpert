<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConfirmedCommercialPriceConditions;
use PHPUnit\Framework\TestCase;

final class ConfirmedCommercialPriceConditionsTest extends TestCase
{
    public function test_confirmation_preserves_source_amounts_and_unknown_tax_while_recording_user_region(): void
    {
        $price = ['unit_price' => '150.25', 'region' => null, 'currency' => 'RUB', 'conditions_missing' => ['region', 'vat_rate', 'delivery_allocation']];
        $conditions = new ConfirmedCommercialPriceConditions;
        self::assertSame(['region', 'delivery'], $conditions->required($price, 'Москва'));
        $confirmed = $conditions->apply($price, ['region' => ['value' => 'Москва', 'basis' => 'Предложение согласовано для объекта.'],
            'delivery' => ['work_item_key' => 'delivery:1', 'basis' => 'Отдельное предложение перевозчика.']], 'Москва');
        self::assertSame('150.25', $confirmed['unit_price']);
        self::assertNull($confirmed['region']);
        self::assertSame('москва', $confirmed['effective_region']);
        self::assertSame(['vat_rate', 'delivery_allocation'], $confirmed['conditions_missing']);
        self::assertSame($price['conditions_missing'], $confirmed['source_conditions_missing']);
    }

    public function test_a_missing_confirmation_or_changed_target_region_does_not_reuse_the_old_acceptance(): void
    {
        $price = ['region' => null, 'conditions_missing' => ['region']];
        $conditions = new ConfirmedCommercialPriceConditions;
        self::assertNull($conditions->apply($price, [], 'Москва'));
        $accepted = ['region' => ['value' => 'Москва', 'basis' => 'Согласованная цена объекта.']];
        self::assertNotNull($conditions->apply($price, $accepted, 'Москва'));
        self::assertNull($conditions->apply($price, $accepted, 'Самара'));
        self::assertNull($conditions->apply($price, ['region' => ['value' => 'Москва', 'basis' => '']], 'Москва'));
    }
}
