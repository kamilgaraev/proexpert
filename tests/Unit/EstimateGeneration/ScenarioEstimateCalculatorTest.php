<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScenarioEstimateCalculatorTest extends TestCase
{
    public function test_unknown_quantities_and_prices_keep_a_known_subtotal_without_a_false_total_or_range(): void
    {
        $known = $this->position('floor', '20', '105.55');
        $unknownVolume = $this->position('wall', null, '200');
        $unknownPrice = $this->position('ceiling', '20', null);
        $result = (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [$known, $unknownVolume, $unknownPrice], 'assumptions' => []]], ['finishing'], ['wall_dimensions']);
        self::assertSame('refined_estimate', $result['result_class']);
        self::assertSame(['RUB' => '2111.00'], $result['scenarios'][0]['known_subtotals']);
        self::assertNull($result['scenarios'][0]['total_by_currency']);
        self::assertNull($result['scenarios'][0]['positions'][1]['total_cost']);
        self::assertNull($result['scenarios'][0]['positions'][2]['unit_price']);
        self::assertNull($result['scenario_range']);
        self::assertFalse($result['can_confirm_for_apply']);
    }

    public function test_ranges_use_complete_compatible_scenarios_and_decimal_line_rounding(): void
    {
        $scenarios = [];
        foreach (['minimum' => ['12', '100'], 'base' => ['20', '105.55'], 'maximum' => ['30', '200.005']] as $id => [$area, $price]) {
            $scenarios[] = ['id' => $id, 'technology' => 'approved-system:1', 'positions' => [$this->position('floor', $area, $price)],
                'assumptions' => [['parameter' => 'area', 'value' => $area, 'basis' => 'verified-analog:1']]];
        }
        $result = (new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], []);
        self::assertSame('scenario_estimate', $result['result_class']);
        self::assertSame(['RUB' => ['minimum' => '1200.00', 'maximum' => '6000.15']], $result['scenario_range']);
        self::assertSame('scenario', $result['range_kind']);
        self::assertFalse($result['accuracy_calibrated']);
    }

    public function test_missing_exchange_rate_keeps_separate_currency_totals_and_unverified_prices_stay_unknown(): void
    {
        $rub = $this->position('floor', '20', '100');
        $usd = $this->position('equipment', '2', '200');
        $usd['price_snapshot']['currency'] = 'USD';
        $unknown = $this->position('installation', '2', '500');
        $unknown['price_snapshot']['verified'] = false;
        $result = (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [$rub, $usd, $unknown], 'assumptions' => []]], ['finishing'], []);
        self::assertSame(['RUB' => '2000.00', 'USD' => '400.00'], $result['scenarios'][0]['known_subtotals']);
        self::assertSame('price_unknown', $result['scenarios'][0]['positions'][2]['pricing_status']);
        self::assertFalse($result['can_confirm_for_apply']);
    }

    public function test_known_scenario_range_is_visible_even_when_scope_requirements_block_verified_status(): void
    {
        $scenarios = array_map(fn (string $id, string $area): array => ['id' => $id,
            'positions' => [$this->position('floor', $area, '100')],
            'assumptions' => [['parameter' => 'area', 'value' => $area, 'basis' => 'user_selected_scenario']]],
            ['minimum', 'base', 'maximum'], ['10', '20', '30']);
        $result = (new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], ['wall_dimensions']);

        self::assertSame(['RUB' => ['minimum' => '1000.00', 'maximum' => '3000.00']], $result['scenario_range']);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame(['wall_dimensions'], $result['missing_requirements']);
    }

    public function test_exclusive_technology_options_are_never_added_together(): void
    {
        $left = $this->position('floor-1', '20', '100');
        $right = $this->position('floor-2', '20', '200');
        $left['exclusive_group'] = $right['exclusive_group'] = 'floor_technology';
        $this->expectException(InvalidArgumentException::class);
        (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [$left, $right], 'assumptions' => []]], ['finishing'], []);
    }

    public function test_catalog_rate_without_observation_date_stays_visible_but_does_not_become_a_confirmed_total(): void
    {
        $position = $this->position('floor', '20', '100');
        $position['price_snapshot']['as_of_date'] = null;
        $result = (new ScenarioEstimateCalculator)->calculate([['id' => 'base', 'positions' => [$position], 'assumptions' => []]], ['finishing'], []);
        self::assertSame('100', $result['scenarios'][0]['positions'][0]['unit_price']);
        self::assertNull($result['scenarios'][0]['positions'][0]['total_cost']);
        self::assertSame('price_conditions_unknown', $result['scenarios'][0]['positions'][0]['pricing_status']);
        self::assertFalse($result['can_confirm_for_apply']);
    }

    private function position(string $key, ?string $quantity, ?string $price): array
    {
        return ['key' => $key, 'section' => 'finishing', 'unit' => 'm2', 'quantity' => $quantity,
            'price_snapshot' => $price === null ? null : ['unit_price' => $price, 'unit' => 'm2', 'currency' => 'RUB',
                'verified' => true, 'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10']];
    }

    public function test_normative_amount_preserves_separate_resource_rounding_and_rejects_a_stale_volume(): void
    {
        $position = $this->position('floor', '3', '0.333333');
        $position['price_snapshot']['source_type'] = 'normative';
        $position['price_snapshot']['calculation_basis'] = ['kind' => 'normative_resource_sum', 'quantity' => '3',
            'source_hash' => str_repeat('a', 64), 'work_cost' => '0.00', 'resources' => [
                ['source_reference' => 'resource:1', 'final_amount' => '0.50'],
                ['source_reference' => 'resource:2', 'final_amount' => '0.51']]];
        $calculate = static fn (array $row): array => (new ScenarioEstimateCalculator)->calculate(
            [['id' => 'base', 'positions' => [$row], 'assumptions' => []]], ['finishing'], []);
        self::assertSame('1.01', $calculate($position)['scenarios'][0]['positions'][0]['total_cost']);
        $position['quantity'] = '4';
        $this->expectException(InvalidArgumentException::class);
        $calculate($position);
    }
}
