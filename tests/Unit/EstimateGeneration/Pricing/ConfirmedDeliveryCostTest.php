<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator;
use PHPUnit\Framework\TestCase;

final class ConfirmedDeliveryCostTest extends TestCase
{
    public function test_delivery_is_counted_once_from_the_confirmed_linked_position_in_the_same_scope(): void
    {
        $result = $this->calculate($this->delivery());
        self::assertSame(['RUB' => '120.00'], $result['scenarios'][0]['total_by_currency']);
        self::assertSame([], $result['scenarios'][0]['price_conditions_unresolved']);
        self::assertSame('shipping', $result['scenarios'][0]['positions'][0]['price_snapshot']['delivery_cost_basis']['work_item_key']);
    }

    public function test_a_different_object_unrelated_work_or_unpriced_delivery_does_not_close_the_requirement(): void
    {
        foreach ([['entity_id' => 'equipment:other'], ['work_key' => 'commissioning'], ['quantity' => null], ['section' => 'electricity']] as $change) {
            $result = $this->calculate([...$this->delivery(), ...$change]);
            self::assertNull($result['scenarios'][0]['total_by_currency']);
            self::assertSame(['delivery_allocation'], $result['scenarios'][0]['price_conditions_unresolved'][0]['conditions']);
        }
    }

    public function test_specific_transport_work_uses_its_separate_delivery_requirement_without_losing_scope_checks(): void
    {
        $delivery = [...$this->delivery(), 'work_key' => 'equipment.transport', 'coverage_requirement' => 'delivery'];
        self::assertSame(['RUB' => '120.00'], $this->calculate($delivery)['scenarios'][0]['total_by_currency']);
        foreach ([['coverage_requirement' => 'commissioning'], ['entity_id' => 'equipment:other'], ['quantity' => null]] as $change) {
            self::assertNull($this->calculate([...$delivery, ...$change])['scenarios'][0]['total_by_currency']);
        }
    }

    private function calculate(array $delivery): array
    {
        $purchase = ['key' => 'purchase', 'entity_id' => 'equipment:1', 'work_key' => 'equipment_purchase', 'section' => 'equipment', 'name' => 'Оборудование',
            'quantity' => '1', 'unit' => 'count', 'price_snapshot' => [...$this->price('100.00'),
                'conditions_missing' => ['delivery_allocation'], 'delivery_work_item_key' => 'shipping']];

        return (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'assumptions' => [], 'positions' => [$purchase, $delivery]]], ['equipment', 'electricity'], [], true);
    }

    private function delivery(): array
    {
        return ['key' => 'shipping', 'entity_id' => 'equipment:1', 'work_key' => 'delivery', 'section' => 'equipment', 'name' => 'Доставка',
            'quantity' => '1', 'unit' => 'count', 'price_snapshot' => $this->price('20.00')];
    }

    private function price(string $amount): array
    {
        return ['source_type' => 'catalog', 'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64), 'unit_price' => $amount,
            'unit' => 'count', 'currency' => 'RUB', 'as_of_date' => '2026-10-13', 'verified' => true, 'applicable' => true,
            'vat_mode' => 'not_applicable', 'conditions_missing' => []];
    }
}
