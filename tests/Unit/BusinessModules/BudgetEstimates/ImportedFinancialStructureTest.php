<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportedEstimateChargeDistributor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetCostBlockParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\UnitNormalizationService;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class ImportedFinancialStructureTest extends TestCase
{
    public function test_global_charges_are_distributed_with_exact_decimal_remainder_without_fitting_source_total(): void
    {
        $rows = array_fill(1, 3, ['current_total_amount' => '100.00', 'direct_costs' => '100.00']);
        $result = (new ImportedEstimateChargeDistributor)->distribute($rows, [
            'total_estimate_cost' => 999,
            'overhead_cost' => '20.00',
            'profit_cost' => '10.00',
        ]);

        self::assertSame('20.00', (string) BigDecimal::sum(...array_column($result, 'overhead_amount')));
        self::assertSame('10.00', (string) BigDecimal::sum(...array_column($result, 'profit_amount')));
        self::assertSame('330.00', (string) BigDecimal::sum(...array_column($result, 'current_total_amount')));
        self::assertSame('6.66', $result[3]['overhead_amount']);
        self::assertSame('3.34', $result[3]['profit_amount']);
    }

    public function test_existing_source_components_are_not_duplicated_and_negative_footer_is_preserved(): void
    {
        $rows = [
            1 => ['current_total_amount' => '-115.00', 'direct_costs' => '-100.00', 'overhead_amount' => '-10.00', 'profit_amount' => '-5.00', 'has_source_overhead_amount' => true, 'has_source_profit_amount' => true],
            2 => ['current_total_amount' => '-100.00', 'direct_costs' => '-100.00'],
        ];
        $result = (new ImportedEstimateChargeDistributor)->distribute($rows, ['overhead_cost' => '-20.00', 'profit_cost' => '-10.00']);
        self::assertSame($rows[1], $result[1]);
        self::assertSame('-10.00', $result[2]['overhead_amount']);
        self::assertSame('-5.00', $result[2]['profit_amount']);
        self::assertSame('-115.00', $result[2]['current_total_amount']);
    }

    public function test_selected_fallback_charges_are_replaced_by_source_footer_components(): void
    {
        $result = (new ImportedEstimateChargeDistributor)->distribute([
            1 => ['current_total_amount' => '230.00', 'direct_costs' => '200.00', 'labor_cost' => '100.00', 'overhead_amount' => '20.00', 'profit_amount' => '10.00'],
        ], ['overhead_cost' => '30.00', 'profit_cost' => '15.00']);
        self::assertSame('245.00', $result[1]['current_total_amount']);
        self::assertSame('200.00', $result[1]['direct_costs']);
    }

    public function test_source_presence_is_independent_for_each_component_including_explicit_zero(): void
    {
        foreach ([0, 4] as $fallback) {
            foreach ([0, 10] as $sourceOverhead) {
                $row = new EstimateImportRowDTO(itemName: 'Работа', quantity: 1, unitPrice: 100, currentTotalAmount: 100 + $sourceOverhead, overheadAmount: $sourceOverhead);
                $total = (new ImportRowPolicy)->totalAmount($row, $sourceOverhead, $fallback);
                $result = (new ImportedEstimateChargeDistributor)->distribute([
                    1 => ['current_total_amount' => $total, 'direct_costs' => 100, 'overhead_amount' => $sourceOverhead, 'profit_amount' => $fallback, 'has_source_overhead_amount' => true, 'has_source_profit_amount' => false],
                ], ['overhead_cost' => $sourceOverhead, 'profit_cost' => 5]);
                self::assertEquals(100 + $sourceOverhead + 5, $result[1]['current_total_amount']);
                self::assertEquals($sourceOverhead, $result[1]['overhead_amount']);
                self::assertEquals(5, $result[1]['profit_amount']);
            }
        }
        foreach ([0, 9] as $fallback) {
            $row = new EstimateImportRowDTO(itemName: 'Работа', quantity: 1, unitPrice: 100, currentTotalAmount: 105, profitAmount: 5);
            $total = (new ImportRowPolicy)->totalAmount($row, $fallback, 5);
            $result = (new ImportedEstimateChargeDistributor)->distribute([
                1 => ['current_total_amount' => $total, 'direct_costs' => 100, 'overhead_amount' => $fallback, 'profit_amount' => 5, 'has_source_overhead_amount' => false, 'has_source_profit_amount' => true],
            ], ['overhead_cost' => 10, 'profit_cost' => 5]);
            self::assertEquals(115, $result[1]['current_total_amount']);
            self::assertEquals(10, $result[1]['overhead_amount']);
            self::assertEquals(5, $result[1]['profit_amount']);
        }
    }

    public function test_declared_global_increment_is_added_to_existing_source_component_and_replaces_only_fallback(): void
    {
        foreach (['overhead', 'profit'] as $charge) {
            $component = $charge.'_amount';
            $source = $charge.'_cost';
            $rows = [1 => ['current_total_amount' => 110, 'direct_costs' => 100, $component => 10, 'has_source_'.$component => true]];
            $result = (new ImportedEstimateChargeDistributor)->distribute($rows, [$source => 30, 'global_'.$source => 20]);
            self::assertEquals(130, $result[1]['current_total_amount']);
            self::assertEquals(30, $result[1][$component]);
            self::assertEquals(20, $result[1]['footer_components'][$component]);

            $rows[1]['has_source_'.$component] = false;
            $result = (new ImportedEstimateChargeDistributor)->distribute($rows, [$source => 20, 'global_'.$source => 20]);
            self::assertEquals(120, $result[1]['current_total_amount']);
            self::assertEquals(20, $result[1][$component]);
        }
    }

    public function test_parser_and_distributor_agree_on_scoped_global_increment_for_both_components(): void
    {
        foreach (['overhead' => 'Накладные расходы', 'profit' => 'Сметная прибыль'] as $charge => $label) {
            $analysis = (new SpreadsheetCostBlockParser)->analyze([
                1 => ['1', 'RATE-1', 'Работа', 'шт', 1, 100, 100],
                2 => [null, $label, null, null, null, null, 10],
                3 => [null, 'Итого с накладными и см. прибылью', null, null, null, null, 110],
                4 => [null, 'Итого по смете', null, null, null, null, 110],
                5 => [null, $label, null, null, null, null, 20],
                6 => [null, 'Всего по смете', null, null, null, null, 130],
            ], ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'unit' => 'D', 'quantity' => 'E', 'unit_price' => 'F', 'total_price' => 'G'], 0);
            $position = $analysis['positions'][1];
            $result = (new ImportedEstimateChargeDistributor)->distribute([
                1 => ['current_total_amount' => $position['total'], 'direct_costs' => 100, 'overhead_amount' => $position['overhead'] ?? 0, 'profit_amount' => $position['profit'] ?? 0, 'has_source_overhead_amount' => $position['overhead'] !== null, 'has_source_profit_amount' => $position['profit'] !== null],
            ], $analysis['footer']);
            self::assertFalse($analysis['total_mismatch']);
            self::assertEquals(130, $result[1]['current_total_amount']);
            self::assertEquals(30, $result[1][$charge.'_amount']);
            self::assertEquals(30, $analysis['footer'][$charge.'_cost']);
            self::assertEquals(100, $analysis['footer']['direct_costs']);
        }
    }

    public function test_named_zero_row_is_imported_and_missing_numeric_row_is_excluded(): void
    {
        $policy = new ImportRowPolicy;
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(itemName: 'Нулевая позиция', quantity: 0, unitPrice: 0, currentTotalAmount: 0)));
        self::assertFalse($policy->shouldImport(new EstimateImportRowDTO(itemName: 'Примечание без чисел')));
        self::assertFalse($policy->shouldImport(new EstimateImportRowDTO(quantity: 0, unitPrice: 0, currentTotalAmount: 0)));
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(itemName: 'Корректировка', quantity: -2, unitPrice: 100, currentTotalAmount: -200)));
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(itemName: 'Фиксированная сумма', quantity: 0, currentTotalAmount: 50)));
    }

    public function test_aggregate_of_existing_source_charges_does_not_erase_selected_fallback_on_other_rows(): void
    {
        $rows = [
            1 => ['current_total_amount' => '115.00', 'overhead_amount' => '10.00', 'profit_amount' => '5.00', 'has_source_overhead_amount' => true, 'has_source_profit_amount' => true],
            2 => ['current_total_amount' => '113.00', 'overhead_amount' => '9.00', 'profit_amount' => '4.00'],
        ];
        self::assertSame($rows, (new ImportedEstimateChargeDistributor)->distribute($rows, ['overhead_cost' => '10.00', 'profit_cost' => '5.00']));
    }

    public function test_unit_spelling_is_canonical_without_rescaling_source_multiplier(): void
    {
        $normalizer = new UnitNormalizationService;
        foreach (['100м2', '100м²', '100m2', '100m²', '100 м²'] as $unit) {
            self::assertSame('100 м²', $normalizer->normalize($unit));
        }
        foreach (['100м3', '100м³', '100m3', '100m³'] as $unit) {
            self::assertSame('100 м³', $normalizer->normalize($unit));
        }
        foreach (['чел. ч', 'чел.-ч', 'чел-час', 'чел/час', 'чел.час'] as $unit) {
            self::assertSame('чел-ч', $normalizer->normalize($unit));
        }
        self::assertSame('1000 м', $normalizer->normalize('1000 м'));
        self::assertSame('1000 м³', $normalizer->normalize('1000м3'));
    }
}
