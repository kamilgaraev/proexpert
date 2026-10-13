<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetAggregateCostCalculator;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetCostBlockParser;
use PHPUnit\Framework\TestCase;

final class SpreadsheetAggregateCostCalculatorTest extends TestCase
{
    public function test_expenses_are_rounded_per_rate_group_before_the_global_index(): void
    {
        $result = (new SpreadsheetAggregateCostCalculator)->calculate([
            1 => $this->position(100, 3),
            2 => $this->position(50, 3),
        ], 1.17, 0, 0);

        self::assertSame(150.0, $result['base_totals']['direct_costs']);
        self::assertSame(1.0, $result['base_totals']['overhead_cost']);
        self::assertSame(1.0, $result['base_totals']['profit_cost']);
        self::assertSame(178.0, $result['totals']['total_estimate_cost']);
        self::assertSame(178.0, array_sum(array_column($result['positions'], 'total')));
        self::assertSame($result['totals']['overhead_cost'], array_sum(array_column($result['positions'], 'overhead')));
        self::assertSame($result['totals']['profit_cost'], array_sum(array_column($result['positions'], 'profit')));
    }

    public function test_signed_positions_and_reversed_input_keep_exact_cent_totals(): void
    {
        $positions = [1 => $this->position(10.25, 7), 2 => $this->position(-9.25, -5)];
        foreach ($positions as &$position) {
            $position['overhead_rule']['rate'] = 15;
            unset($position['profit_rule']);
        }
        unset($position);
        $calculator = new SpreadsheetAggregateCostCalculator;
        $result = $calculator->calculate($positions, 1.3333, 2, 2);
        $reversed = $calculator->calculate(array_reverse($positions, true), 1.3333, 2, 2);

        self::assertSame(1.73, $result['totals']['total_estimate_cost']);
        self::assertEqualsWithDelta(1.73, array_sum(array_column($result['positions'], 'total')), 0.000001);
        self::assertLessThan(0, $result['positions'][2]['total']);
        self::assertSame($result['positions'][1], $reversed['positions'][1]);
        self::assertSame($result['positions'][2], $reversed['positions'][2]);
    }

    public function test_source_total_is_only_a_control_and_never_changes_the_calculated_positions(): void
    {
        $rows = $this->rows();
        $mapping = $this->mapping();
        $parser = new SpreadsheetCostBlockParser;
        $valid = $parser->analyze($rows, $mapping, 0);
        self::assertFalse($valid['total_mismatch']);
        self::assertSame(178.0, array_sum(array_column($valid['positions'], 'total')));

        $rows[12][8] = 179;
        $invalid = $parser->analyze($rows, $mapping, 0);
        self::assertTrue($invalid['total_mismatch']);
        self::assertSame($valid['positions'], $invalid['positions']);
        self::assertSame(178.0, $invalid['footer']['total_estimate_cost']);
    }

    public function test_currency_formatting_does_not_change_rounding(): void
    {
        $rows = $this->rows();
        $parser = new SpreadsheetCostBlockParser;
        $numeric = $parser->analyze($rows, $this->mapping(), 0);
        foreach ($rows as &$row) {
            foreach ($row as &$cell) {
                if (is_int($cell)) {
                    $cell = number_format($cell, 2, ',', "\u{202F}").' руб.';
                }
            }
            unset($cell);
        }
        unset($row);
        $formatted = $parser->analyze($rows, $this->mapping(), 0);
        self::assertFalse($formatted['total_mismatch']);
        self::assertSame($numeric['positions'], $formatted['positions']);
    }

    public function test_blank_rounded_charges_keep_their_explicit_percentage_rules(): void
    {
        $rows = $this->rows();
        $parser = new SpreadsheetCostBlockParser;
        $expected = $parser->analyze($rows, $this->mapping(), 0);
        $rows[3][6] = null;
        $rows[7][6] = null;
        $actual = $parser->analyze($rows, $this->mapping(), 0);
        self::assertFalse($actual['total_mismatch']);
        self::assertSame($expected['positions'], $actual['positions']);
    }

    public function test_final_net_and_vat_controls_are_checked(): void
    {
        $rows = $this->rows();
        $parser = new SpreadsheetCostBlockParser;
        $rows[13] = ['Итого по смете', null, null, null, null, null, null, null, 999];
        self::assertTrue($parser->analyze($rows, $this->mapping(), 0)['total_mismatch']);
        $rows[13][8] = 178;
        $rows[14] = ['НДС 22%', null, null, null, null, null, null, null, 39.16];
        $rows[15] = ['Всего по смете', null, null, null, null, null, null, null, 217.16];
        self::assertFalse($parser->analyze($rows, $this->mapping(), 0)['total_mismatch']);
        $rows[13][8] = 999;
        self::assertTrue($parser->analyze($rows, $this->mapping(), 0)['total_mismatch']);
        $rows[13][8] = 178;
        $rows[15][8] = 217.17;
        self::assertTrue($parser->analyze($rows, $this->mapping(), 0)['total_mismatch']);
        $rows[15][8] = 217.16;
        $rows[14][8] = 39.17;
        self::assertTrue($parser->analyze($rows, $this->mapping(), 0)['total_mismatch']);
    }

    public function test_all_blank_rounded_charges_follow_the_position_rounding_profile(): void
    {
        $rows = [];
        foreach ([100, 50, 25] as $number => $amount) {
            $rows[] = [(string) ($number + 1), 'RATE-'.$number, 'Work', null, null, 1, $amount, null, $amount];
            $rows[] = [null, 'Накладные расходы 20% ФОТ (от 1)'];
            $rows[] = [null, 'Всего с учетом "СМР=1"', null, null, null, null, $amount];
        }
        $rows[] = ['Итого прямые затраты по смете в базисных ценах', null, null, null, null, null, null, null, 175];
        $rows[] = ['Накладные расходы', null, null, null, null, null, null, null, 1];
        $rows[] = ['Всего с учетом "СМР=1"', null, null, null, null, null, null, null, 176];
        $result = (new SpreadsheetCostBlockParser)->analyze($rows, $this->mapping(), -1);
        self::assertFalse($result['total_mismatch']);
        self::assertSame(1.0, $result['footer']['overhead_cost']);
        self::assertSame(176.0, array_sum(array_column($result['positions'], 'total')));
    }

    public function test_unknown_canceling_charges_are_rejected(): void
    {
        $rows = [
            1 => ['1', 'RATE-1', 'Work', null, null, 1, 100, null, 100],
            2 => [null, 'Накладные расходы', null, null, null, null, 20],
            3 => [null, 'Сметная прибыль', null, null, null, null, -20],
            4 => ['Итого прямые затраты по смете в базисных ценах', null, null, null, null, null, null, null, 100],
            5 => ['Всего с учетом "СМР=1"', null, null, null, null, null, null, null, 100],
        ];
        $result = (new SpreadsheetCostBlockParser)->analyze($rows, $this->mapping(), 0);
        self::assertTrue($result['total_mismatch']);
        self::assertTrue($result['unsupported_rule']);
        self::assertSame([], $result['positions']);
    }

    private function rows(): array
    {
        return [
            1 => ['1', 'RATE-1', 'Work one', null, null, 1, 100, null, 100],
            2 => [null, 'Накладные расходы 20% ФОТ (от 3)', null, null, null, null, 1],
            3 => [null, 'Сметная прибыль 10% ФОТ (от 3)', null, null, null, null, 0],
            4 => [null, 'Всего с учетом "СМР=1,17"', null, null, null, null, 118],
            5 => ['2', 'RATE-2', 'Work two', null, null, 1, 50, null, 50],
            6 => [null, 'Накладные расходы 20% ФОТ (от 3)', null, null, null, null, 1],
            7 => [null, 'Сметная прибыль 10% ФОТ (от 3)', null, null, null, null, 0],
            8 => [null, 'Всего с учетом "СМР=1,17"', null, null, null, null, 60],
            9 => ['Итого прямые затраты по смете в базисных ценах', null, null, null, null, null, null, null, 150],
            10 => ['Накладные расходы', null, null, null, null, null, null, null, 1],
            11 => ['Сметная прибыль', null, null, null, null, null, null, null, 1],
            12 => ['Всего с учетом "СМР=1,17"', null, null, null, null, null, null, null, 178],
        ];
    }

    private function mapping(): array
    {
        return ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'quantity' => 'F', 'unit_price' => 'G', 'total_price' => 'I'];
    }

    private function position(float $amount, float $basis): array
    {
        return [
            'base_total' => $amount,
            'base_price' => abs($amount),
            'quantity' => $amount < 0 ? -1 : 1,
            'overhead_rule' => ['rate' => 20, 'basis' => $basis],
            'profit_rule' => ['rate' => 10, 'basis' => $basis],
        ];
    }
}
