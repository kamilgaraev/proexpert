<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimateOperationCalculator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EstimateOperationSignedQuantityTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $root = dirname(__DIR__, 4);
        $application = new Application($root);
        $application->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $application->instance('translator', new Translator(new FileLoader(new Filesystem(), $root.'/lang'), 'ru'));
        $application->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_positive_exclusions_keep_the_existing_contract(): void
    {
        $sources = [
            $this->source(1, 'base', [$this->item(1, 1, '100')]),
            $this->source(2, 'subtract', [$this->item(2, 2, '30')]),
            $this->source(3, 'add', [$this->item(3, 3, '20')]),
        ];
        $result = $this->calculate($sources, false);
        self::assertSame([], $this->codes($result));
        self::assertSame('90.00000000', $result['output'][0]['quantity']);
        self::assertSame('450.00', $result['summary']['total_amount']);
    }

    public function test_signed_exclusion_reduces_positive_base_without_double_subtraction(): void
    {
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '100')]),
            $this->source(2, 'subtract', [$this->item(2, 2, '-30')]),
            $this->source(3, 'add', [$this->item(3, 3, '20')]),
        ]);
        self::assertSame([], $this->codes($result));
        self::assertSame('90.00000000', $result['output'][0]['quantity']);
        self::assertSame('450.00', $result['summary']['total_amount']);
        self::assertSame('-30', $result['output'][0]['sources'][1]['quantity']);
    }

    public function test_signed_exclusion_can_cancel_part_of_a_negative_material_position(): void
    {
        $base = $this->item(1, 1, '-100');
        $base['attributes']['item_type'] = 'material';
        $base['attributes']['materials_cost'] = '-500';
        $subtract = $this->item(2, 2, '30');
        $subtract['attributes']['item_type'] = 'material';
        $result = $this->calculate([$this->source(1, 'base', [$base]), $this->source(2, 'subtract', [$subtract])]);
        self::assertSame([], $this->codes($result));
        self::assertSame('-70.00000000', $result['output'][0]['quantity']);
        self::assertSame('-350.00', $result['output'][0]['node']['attributes']['materials_cost']);
        self::assertSame('-350.00', $result['summary']['total_amount']);
        self::assertSame('-427.00', $result['summary']['total_amount_with_vat']);
    }

    public function test_default_mode_still_rejects_negative_exclusions(): void
    {
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '100')]),
            $this->source(2, 'subtract', [$this->item(2, 2, '-30')]),
        ], false);
        self::assertContains('quantity_invalid', $this->codes($result));
    }

    public function test_signed_exclusion_must_have_the_opposite_sign_to_the_base(): void
    {
        foreach (['100', '-100'] as $quantity) {
            $result = $this->calculate([
                $this->source(1, 'base', [$this->item(1, 1, $quantity)]),
                $this->source(2, 'subtract', [$this->item(2, 2, $quantity)]),
            ]);
            self::assertContains('subtraction_sign_invalid', $this->codes($result));
        }
    }

    public function test_multiple_exclusions_cannot_cross_zero_even_with_additions(): void
    {
        foreach ([['100', '-60'], ['-100', '60']] as [$base, $subtract]) {
            $result = $this->calculate([
                $this->source(1, 'base', [$this->item(1, 1, $base)]),
                $this->source(2, 'subtract', [$this->item(2, 2, $subtract), $this->item(2, 3, $subtract)]),
                $this->source(3, 'add', [$this->item(3, 4, '100')]),
            ]);
            self::assertContains('execution_exceeded', $this->codes($result));
        }
    }

    public function test_signed_quantity_keeps_base_prices_and_creates_no_zero_quantity_money_residual(): void
    {
        foreach ([['2', '-2'], ['-2', '2']] as [$baseQuantity, $subtractQuantity]) {
            $base = $this->item(1, 1, $baseQuantity, '10');
            $subtract = $this->item(2, 2, $subtractQuantity, '9');
            $result = $this->calculate([$this->source(1, 'base', [$base]), $this->source(2, 'subtract', [$subtract])]);
            self::assertSame([], $this->codes($result));
            self::assertSame('0.00000000', $result['output'][0]['quantity']);
            self::assertSame('0.00', $result['summary']['total_amount']);
            self::assertSame('10', $result['output'][0]['node']['attributes']['unit_price']);
        }
    }

    public function test_signed_matching_uses_unique_identity_across_different_section_names(): void
    {
        $base = $this->item(1, 1, '100');
        $base['section_path'] = ['Исходные работы'];
        $subtract = $this->item(2, 2, '-30');
        $subtract['section_path'] = ['Исключение'];
        $result = $this->calculate([$this->source(1, 'base', [$base]), $this->source(2, 'subtract', [$subtract])]);
        self::assertSame([], $this->codes($result));
        self::assertSame('70.00000000', $result['output'][0]['quantity']);
    }

    public function test_ambiguous_signed_matches_require_an_explicit_target(): void
    {
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '100'), $this->item(1, 2, '100')]),
            $this->source(2, 'subtract', [$this->item(2, 3, '-30')]),
        ]);
        self::assertContains('subtract_match_required', $this->codes($result));
    }

    public function test_execution_of_a_child_protects_negative_parent_remainder(): void
    {
        foreach (['200', '-200'] as $childQuantity) {
            foreach (['20' => false, '30' => true] as $excluded => $blocked) {
                $parent = $this->item(1, 1, '-100');
                $child = $this->item(1, 2, $childQuantity);
                $child['attributes']['parent_work_id'] = 1;
                $child['execution']['quantity'] = '160';
                $result = $this->calculate([
                    $this->source(1, 'base', [$parent, $child]),
                    $this->source(2, 'subtract', [$this->item(2, 3, (string) $excluded)]),
                ], resolutions: ['match:2:3' => ['target_key' => '1:1']]);
                self::assertSame($blocked, in_array('execution_exceeded', $this->codes($result), true));
                if (! $blocked) {
                    self::assertSame([], $this->codes($result));
                    self::assertSame(FinanceDecimal::multiply($childQuantity, '0.8', 8), $result['output'][0]['node']['children'][0]['quantity']);
                }
            }
        }
    }

    public function test_execution_guard_checks_scaled_children_without_rounding_the_floor_down(): void
    {
        $parent = $this->item(1, 1, '-0.00000003');
        $child = $this->item(1, 2, '1');
        $child['attributes']['parent_work_id'] = 1;
        $child['execution']['quantity'] = '0.33333334';
        $result = $this->calculate([
            $this->source(1, 'base', [$parent, $child]),
            $this->source(2, 'subtract', [$this->item(2, 3, '0.00000002')]),
        ], resolutions: ['match:2:3' => ['target_key' => '1:1']]);
        self::assertContains('execution_exceeded', $this->codes($result));
    }

    public function test_unknown_execution_blocks_signed_exclusion(): void
    {
        $base = $this->item(1, 1, '-100');
        $base['execution']['unknown'] = true;
        $result = $this->calculate([
            $this->source(1, 'base', [$base]), $this->source(2, 'subtract', [$this->item(2, 2, '30')]),
        ]);
        self::assertContains('execution_unknown', $this->codes($result));
    }

    public function test_positive_addition_stays_separate_from_negative_base(): void
    {
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '-10')]),
            $this->source(2, 'add', [$this->item(2, 2, '10')]),
        ]);
        self::assertSame([], $this->codes($result));
        self::assertCount(2, $result['output']);
        self::assertSame('0.00', $result['summary']['total_amount']);
        $matched = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '-10')]),
            $this->source(2, 'add', [$this->item(2, 2, '10')]),
        ], resolutions: ['match:2:2' => ['target_key' => '1:1']]);
        self::assertContains('match_required', $this->codes($matched));
    }

    public function test_negative_additions_remain_invalid(): void
    {
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '100')]),
            $this->source(2, 'add', [$this->item(2, 2, '-10')]),
        ]);
        self::assertContains('quantity_invalid', $this->codes($result));
    }

    public function test_signed_numeric_limits_are_checked_on_both_sides(): void
    {
        $base = $this->item(1, 1, '-1000000000000', '1');
        $result = $this->calculate([$this->source(1, 'base', [$base])]);
        self::assertContains('numeric_range', $this->codes($result));
    }

    public function test_signed_cancellation_cannot_hide_header_component_overflow(): void
    {
        $base = $this->item(1, 1, '-1', '3000000000000');
        $base['attributes']['name'] = 'База';
        $base['attributes']['direct_costs'] = '-2000000000000';
        $base['attributes']['overhead_amount'] = '-1000000000000';
        $first = $this->item(2, 2, '1', '6000000000000');
        $first['attributes']['name'] = 'Первое дополнение';
        $first['attributes']['normative_rate_code'] = 'ADD-1';
        $second = $this->item(3, 3, '1', '6000000000000');
        $second['attributes']['name'] = 'Второе дополнение';
        $second['attributes']['normative_rate_code'] = 'ADD-2';
        $result = (new EstimateOperationCalculator())->calculate([
            $this->source(1, 'base', [$base]), $this->source(2, 'add', [$first]), $this->source(3, 'add', [$second]),
        ], ['mode' => 'adjust', 'reprice' => false, 'subtraction_quantity_mode' => 'signed', 'currency' => 'RUB', 'vat_rate' => '0'], []);
        self::assertContains('numeric_range', $this->codes($result));
    }

    public function test_inactive_additions_remain_separate_from_active_positions_in_both_orders(): void
    {
        foreach ([false, true] as $inactiveBase) {
            $base = $this->item(1, 1, '10');
            $base['attributes']['is_not_accounted'] = $inactiveBase;
            $addition = $this->item(2, 2, '4');
            $addition['attributes']['is_not_accounted'] = ! $inactiveBase;
            $result = $this->calculate([$this->source(1, 'base', [$base]), $this->source(2, 'add', [$addition])]);

            self::assertSame($inactiveBase ? '20.00' : '50.00', $result['summary']['total_amount']);
            self::assertSame('50.00', $result['output'][0]['node']['attributes']['total_amount']);
            self::assertSame([], $this->codes($result));
            self::assertCount(2, $result['output']);
            self::assertSame($inactiveBase, $result['output'][0]['node']['attributes']['is_not_accounted']);
            self::assertSame(! $inactiveBase, $result['output'][1]['node']['attributes']['is_not_accounted']);
            self::assertSame('10', $result['output'][0]['quantity']);
            self::assertSame('4', $result['output'][1]['quantity']);
        }
    }

    public function test_manual_addition_match_cannot_activate_an_inactive_contribution(): void
    {
        $addition = $this->item(2, 2, '4');
        $addition['attributes']['is_not_accounted'] = true;
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '10')]),
            $this->source(2, 'add', [$addition]),
        ], resolutions: ['match:2:2' => ['target_key' => '1:1']]);

        self::assertContains('match_required', $this->codes($result));
        self::assertSame('50.00', $result['summary']['total_amount']);
        self::assertSame('50.00', $result['output'][0]['node']['attributes']['total_amount']);
        self::assertCount(2, $result['output']);
    }

    public function test_inactive_children_cannot_merge_into_active_children(): void
    {
        $baseChild = $this->item(1, 2, '20', '3');
        $baseChild['attributes']['parent_work_id'] = 1;
        $additionChild = $this->item(2, 4, '8', '3');
        $additionChild['attributes']['parent_work_id'] = 3;
        $additionChild['attributes']['is_not_accounted'] = true;
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '10'), $baseChild]),
            $this->source(2, 'add', [$this->item(2, 3, '4'), $additionChild]),
        ]);

        self::assertContains('match_required', $this->codes($result));
        self::assertCount(2, $result['output']);
        self::assertSame('60.00', $result['output'][0]['node']['children'][0]['attributes']['total_amount']);
        self::assertFalse($result['output'][0]['node']['children'][0]['attributes']['is_not_accounted']);
        self::assertTrue($result['output'][1]['node']['children'][0]['attributes']['is_not_accounted']);
    }

    public function test_inactive_exclusions_have_no_effect_in_either_quantity_mode(): void
    {
        foreach ([false, true] as $signed) {
            $subtraction = $this->item(2, 2, $signed ? '-30' : '30');
            $subtraction['attributes']['is_not_accounted'] = true;
            $result = $this->calculate([
                $this->source(1, 'base', [$this->item(1, 1, '100')]),
                $this->source(2, 'subtract', [$subtraction]),
            ], $signed);

            self::assertSame([], $this->codes($result));
            self::assertSame('100', $result['output'][0]['quantity']);
            self::assertSame('500.00', $result['summary']['total_amount']);
        }
    }

    public function test_exclusions_automatically_match_only_accounted_base_positions(): void
    {
        $inactive = $this->item(1, 2, '100');
        $inactive['attributes']['is_not_accounted'] = true;
        $result = $this->calculate([
            $this->source(1, 'base', [$this->item(1, 1, '100'), $inactive]),
            $this->source(2, 'subtract', [$this->item(2, 3, '-30')]),
        ]);

        self::assertSame([], $this->codes($result));
        self::assertSame('70.00000000', $result['output'][0]['quantity']);
        self::assertSame('100', $result['output'][1]['quantity']);
        self::assertSame('350.00', $result['summary']['total_amount']);
    }

    public function test_manual_exclusion_cannot_target_an_inactive_base_position(): void
    {
        $base = $this->item(1, 1, '100');
        $base['attributes']['is_not_accounted'] = true;
        $result = $this->calculate([
            $this->source(1, 'base', [$base]),
            $this->source(2, 'subtract', [$this->item(2, 2, '-30')]),
        ], resolutions: ['match:2:2' => ['target_key' => '1:1']]);

        self::assertContains('subtract_match_required', $this->codes($result));
        self::assertSame('100', $result['output'][0]['quantity']);
        self::assertSame('0.00', $result['summary']['total_amount']);
    }

    public function test_zero_quantity_informational_amounts_follow_the_parent_remainder(): void
    {
        foreach ([['0', '20.00', '50.00'], ['-4', '12.00', '30.00'], ['-10', '0.00', '0.00']] as [$excluded, $childAmount, $total]) {
            $child = $this->item(1, 2, '0', '0');
            $child['attributes']['parent_work_id'] = 1;
            $child['attributes']['is_not_accounted'] = true;
            $child['attributes']['metadata'] = ['is_informative_row' => true];
            foreach (['direct_costs', 'total_amount', 'current_total_amount'] as $field) {
                $child['attributes'][$field] = '20.00';
            }
            $sources = [$this->source(1, 'base', [$this->item(1, 1, '10'), $child])];
            if ($excluded !== '0') {
                $sources[] = $this->source(2, 'subtract', [$this->item(2, 3, $excluded)]);
            }
            $result = $this->calculate($sources, resolutions: ['match:2:3' => ['target_key' => '1:1']]);

            self::assertSame([], $this->codes($result));
            self::assertSame($total, $result['summary']['total_amount']);
            self::assertSame($childAmount, $result['output'][0]['node']['children'][0]['attributes']['total_amount']);
            self::assertSame($childAmount, $result['output'][0]['node']['children'][0]['attributes']['current_total_amount']);
            self::assertTrue($result['output'][0]['node']['children'][0]['attributes']['is_not_accounted']);
        }
    }

    private function calculate(array $sources, bool $signed = true, array $resolutions = []): array
    {
        return (new EstimateOperationCalculator())->calculate($sources, [
            'mode' => 'adjust', 'reprice' => false, 'subtraction_quantity_mode' => $signed ? 'signed' : 'positive',
            'currency' => 'RUB', 'vat_rate' => '22',
        ], [], $resolutions);
    }

    private function source(int $id, string $role, array $items): array
    {
        return ['id' => $id, 'name' => 'Смета', 'role' => $role, 'header' => [
            'metadata' => ['currency' => 'RUB'], 'contingency_amount' => '0', 'overhead_rate' => '0', 'profit_rate' => '0',
        ], 'items' => $items];
    }

    private function item(int $estimateId, int $id, string $quantity, string $price = '5'): array
    {
        $amount = FinanceDecimal::multiply($quantity, $price);
        return ['attributes' => [
            'id' => $id, 'estimate_id' => $estimateId, 'parent_work_id' => null, 'name' => 'Позиция', 'item_type' => 'work',
            'quantity' => $quantity, 'quantity_total' => $quantity, 'quantity_coefficient' => '1', 'price_coefficient' => '1',
            'coefficient_total' => '1', 'applied_coefficients' => null, 'custom_resources' => null,
            'is_not_accounted' => false, 'is_manual' => true, 'direct_costs' => $amount, 'total_amount' => $amount,
            'current_total_amount' => $amount, 'unit_price' => $price, 'current_unit_price' => $price, 'base_unit_price' => null,
            'normative_rate_code' => 'WORK-001', 'code' => null, 'material_id' => null, 'catalog_item_id' => null,
            'normative_rate_id' => null, 'measurement_unit_id' => 1,
        ], 'unit' => 'м', 'section_path' => [], 'quantity' => $quantity, 'resources' => [], 'works' => [], 'totals' => [],
            'execution' => ['quantity' => '0', 'unknown' => false]];
    }

    private function codes(array $result): array
    {
        return array_merge(...array_map(static fn (array $row): array => array_column($row['conflicts'], 'code'), $result['rows']));
    }
}
