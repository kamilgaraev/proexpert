<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecutionSummary;
use Tests\TestCase;

final class EstimateFinanceExecutionSummaryTest extends TestCase
{
    public function test_totals_sections_and_positions_share_sources_without_double_counting_or_currency_conversion(): void
    {
        $revenue = $this->fact(1, 11, 1, 'revenue', 'RUB', '2', '100.01', '83.34');
        $cost = $this->fact(2, 11, 2, 'cost', 'RUB', '1', '40.00', null);
        $usd = $this->fact(3, 12, 1, 'revenue', 'USD', '1', '12.34', '10');
        $allocations = [
            ['contract_id' => 1, 'target_key' => 'i:11', 'currency' => 'RUB', 'quantity' => '5'],
            ['contract_id' => 2, 'target_key' => 'i:11', 'currency' => 'RUB', 'quantity' => '4'],
        ];
        $targets = ['i:11' => ['section_id' => 2, 'parent_key' => null], 'i:12' => ['section_id' => 3, 'parent_key' => null]];
        $sections = [['id' => 1, 'parent_section_id' => null], ['id' => 2, 'parent_section_id' => 1], ['id' => 3, 'parent_section_id' => 1]];
        $calculator = new EstimateFinanceExecutionSummary;
        $gross = $calculator->calculate([$revenue, $cost, $usd, $revenue], $allocations, $targets, $sections, 'with_vat');
        self::assertSame('100.01', $gross['totals']['RUB']['revenue']);
        self::assertSame('40.00', $gross['totals']['RUB']['cost']);
        self::assertSame('60.01', $gross['totals']['RUB']['difference']);
        self::assertSame(2, $gross['totals']['RUB']['sources_count']);
        self::assertSame('12.34', $gross['totals']['USD']['revenue']);
        self::assertSame($gross['totals'], $gross['sections'][1]);
        self::assertSame($gross['totals']['RUB'], $gross['positions'][0]['currencies']['RUB']);
        self::assertSame('3.00000000', $gross['contract_quantities'][0]['remaining_quantity']);
        self::assertSame('3.00000000', $gross['contract_quantities'][1]['remaining_quantity']);
        self::assertNull($gross['contract_quantities'][2]['planned_quantity']);
        self::assertNull($gross['contract_quantities'][2]['remaining_quantity']);
        $net = $calculator->calculate([$revenue, $cost, $usd], $allocations, $targets, $sections, 'without_vat');
        self::assertSame('83.34', $net['totals']['RUB']['revenue']);
        self::assertNull($net['totals']['RUB']['cost']);
        self::assertNull($net['totals']['RUB']['difference']);
        self::assertSame(1, $net['totals']['RUB']['cost_unpriced_count']);
    }

    public function test_unknown_direction_preserves_known_part_and_overrun_is_explicit(): void
    {
        $known = $this->fact(1, 11, 2, 'cost', 'RUB', '3', '10.01', '8.34');
        $unknown = $this->fact(2, 12, 3, 'unknown', 'RUB', '1', '20', '20');
        $unknown['quantity'] = null;
        $report = (new EstimateFinanceExecutionSummary)->calculate([$known, $unknown], [
            ['contract_id' => 2, 'target_key' => 'i:11', 'currency' => 'RUB', 'quantity' => '1'],
        ], [], [], 'without_vat');
        self::assertSame('8.34', $report['totals']['RUB']['known_cost']);
        self::assertNull($report['totals']['RUB']['revenue']);
        self::assertNull($report['totals']['RUB']['cost']);
        self::assertNull($report['totals']['RUB']['difference']);
        self::assertSame(1, $report['totals']['RUB']['unknown_direction_count']);
        self::assertSame('0.00000000', $report['contract_quantities'][0]['remaining_quantity']);
        self::assertSame('2.00000000', $report['contract_quantities'][0]['overrun_quantity']);
        self::assertNull($report['contract_quantities'][1]['accepted_quantity']);
    }

    private function fact(int $id, int $item, int $contract, string $side, string $currency, string $quantity, string $gross, ?string $net): array
    {
        return ['act_id' => 1, 'source_type' => 'act_line', 'source_id' => $id, 'item_id' => $item,
            'contract_id' => $contract, 'side' => $side, 'currency' => $currency, 'quantity' => $quantity,
            'amount_with_vat' => $gross, 'amount_without_vat' => $net];
    }
}
