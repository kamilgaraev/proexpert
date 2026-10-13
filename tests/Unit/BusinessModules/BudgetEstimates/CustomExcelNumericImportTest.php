<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetCostBlockParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetNumberParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use PHPUnit\Framework\TestCase;

final class CustomExcelNumericImportTest extends TestCase
{
    public function test_native_and_text_decimal_quantities_keep_three_decimal_places(): void
    {
        foreach ([-8.747, -0.014, 1.205, '-0,014', '1.205'] as $quantity) {
            $items = $this->rows([1 => ['1', 'MAT-1', 'Материал', null, null, $quantity, 10, null, -1]]);
            self::assertEqualsWithDelta((float) str_replace(',', '.', (string) $quantity), $items[0]->quantity, 0.000001);
        }
    }

    public function test_multiline_price_keeps_the_total_price_instead_of_concatenating_components(): void
    {
        $items = $this->rows([1 => ['1', 'RATE-1', 'Работа', null, null, 2, "9\u{00a0}408,65\n173,28", null, 18817]]);

        self::assertSame(9408.65, $items[0]->unitPrice);
        self::assertSame(18817.0, $items[0]->currentTotalAmount);
    }

    public function test_ordinary_position_without_a_total_uses_quantity_times_price(): void
    {
        $items = $this->rows([1 => ['1', 'RATE-1', 'Работа', null, null, 2, 100]]);

        self::assertSame(100.0, $items[0]->unitPrice);
        self::assertSame(200.0, $items[0]->currentTotalAmount);
    }

    public function test_cost_block_keeps_charges_and_index_on_the_position(): void
    {
        $items = $this->rows([
            1 => ['1', 'RATE-1', 'Работа', null, null, 2, "9 408,65\n173,28", null, 18817],
            2 => [null, 'Накладные расходы 130% ФОТ (от 443)', null, null, null, null, 576],
            3 => [null, 'Сметная прибыль 89% ФОТ (от 443)', null, null, null, null, 394],
            4 => [null, 'Итого c накладными и см. прибылью', null, null, null, null, 19787],
            5 => [null, 'Всего с учетом " СМР=6,23"', null, null, null, null, 123273],
        ]);

        self::assertCount(1, $items);
        self::assertSame(123273.0, $items[0]->currentTotalAmount);
        self::assertSame(3588.48, $items[0]->overheadAmount);
        self::assertSame(2454.62, $items[0]->profitAmount);
        self::assertSame(6.23, $items[0]->priceIndex);
        self::assertSame(9408.65, $items[0]->baseUnitPrice);
    }

    public function test_general_footer_does_not_replace_the_last_position_total(): void
    {
        $items = $this->rows([
            1 => ['1', 'MAT-1', 'Материал', null, null, -0.014, 28557.38, null, -400],
            2 => [null, 'Итого по смете', null, null, null, null, 100000],
            3 => [null, 'Сметная прибыль', null, null, null, null, 10000],
        ]);

        self::assertCount(1, $items);
        self::assertSame(-400.0, $items[0]->currentTotalAmount);
        self::assertNull($items[0]->profitAmount);
    }

    public function test_common_index_is_applied_only_when_declared_total_confirms_it(): void
    {
        $mapping = ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'quantity' => 'F', 'unit_price' => 'G', 'total_price' => 'I'];
        $rows = [
            1 => ['1', 'RATE-1', 'Работа', null, null, 1, 100, null, 100],
            2 => [null, 'Накладные расходы', null, null, null, null, 10],
            3 => [null, 'Итого c накладными и см. прибылью', null, null, null, null, 110],
            4 => [null, 'Всего с учетом "СМР=2"', null, null, null, null, 220],
            5 => ['2', 'MAT-1', 'Материал', null, null, -1, 50, null, -50],
            6 => [null, 'Итого по смете', null, null, null, null, 120],
        ];
        $parser = new SpreadsheetCostBlockParser;
        $analysis = $parser->analyze($rows, $mapping, 0);

        self::assertSame(-100.0, $analysis['positions'][5]['total']);
        self::assertSame(2.0, $analysis['positions'][5]['index']);
        self::assertSame(20.0, $analysis['footer']['overhead_cost']);

        $rows[6][6] = 170;
        $analysis = $parser->analyze($rows, $mapping, 0);
        self::assertSame(-50.0, $analysis['positions'][5]['total']);
        self::assertSame(1.0, $analysis['positions'][5]['index']);
    }

    public function test_numeric_parser_keeps_localized_and_grouped_prices_without_accepting_corrupt_text(): void
    {
        $parser = new SpreadsheetNumberParser;
        foreach (['1 234,567' => 1234.567, '1.234.567,89' => 1234567.89, '1,234,567.89' => 1234567.89, '1.234.567' => 1234567.0] as $input => $expected) {
            self::assertSame($expected, $parser->parse($input));
        }
        self::assertNull($parser->parse('12oops34'));
        self::assertNull($parser->parse('1,2,3'));
    }

    public function test_vat_rows_are_excluded_and_gross_footer_keeps_the_net_amount(): void
    {
        $rows = [
            1 => ['1', 'RATE-1', 'Работа', null, null, 2, 100, null, 200],
            2 => [null, null, 'Итого по смете', null, null, null, null, null, 200],
            3 => [null, null, 'НДС 22%', null, null, null, null, null, 44],
            4 => [null, null, 'Всего по смете', null, null, null, null, null, 244],
        ];
        self::assertCount(1, $this->rows($rows));
        $analysis = $this->analyze($rows);
        self::assertSame(200.0, $analysis['footer']['total_estimate_cost']);
        self::assertSame(22.0, $analysis['footer']['vat_rate']);
        self::assertFalse($analysis['total_mismatch']);
    }

    public function test_global_footer_charges_are_counted_when_matching_the_declared_total(): void
    {
        $analysis = $this->analyze([
            1 => ['1', 'RATE-1', 'Работа', null, null, 2, 100, null, 200],
            2 => [null, 'Итого по смете', null, null, null, null, 200],
            3 => [null, 'Накладные расходы', null, null, null, null, 20],
            4 => [null, 'Сметная прибыль', null, null, null, null, 10],
            5 => [null, 'Всего по смете', null, null, null, null, 230],
        ]);
        self::assertFalse($analysis['total_mismatch']);
        self::assertSame(230.0, $analysis['footer']['total_estimate_cost']);
        self::assertSame(200.0, $analysis['footer']['direct_costs']);
    }

    public function test_code_only_positions_and_unnumbered_rows_are_included_in_the_control_total(): void
    {
        foreach ([['1', 'MAT-1', null], [null, null, 'Материал']] as $identity) {
            $row = array_merge($identity, [null, null, 2, 100, null, 200]);
            $analysis = $this->analyze([1 => $row, 2 => [null, 'Итого по смете', null, null, null, null, 200]]);
            self::assertFalse($analysis['total_mismatch']);
            self::assertSame(200.0, $analysis['positions'][1]['total']);
        }
    }

    public function test_negative_position_charges_are_included_only_once(): void
    {
        $items = $this->rows([
            1 => ['1', 'RATE-1', 'Работа', null, null, -1, 100, null, -100],
            2 => [null, 'Накладные расходы', null, null, null, null, -10],
            3 => [null, 'Сметная прибыль', null, null, null, null, -5],
            4 => [null, 'Итого c накладными и см. прибылью', null, null, null, null, -115],
        ]);
        self::assertCount(1, $items);
        self::assertSame(-115.0, (new ImportRowPolicy)->totalAmount($items[0]));
        self::assertSame(125.0, (new ImportRowPolicy)->totalAmount(new EstimateImportRowDTO(quantity: 1, unitPrice: 100, overheadAmount: 20, profitAmount: 5)));
    }

    private function analyze(array $rows): array
    {
        return (new SpreadsheetCostBlockParser)->analyze($rows, ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'quantity' => 'F', 'unit_price' => 'G', 'total_price' => 'I'], 0);
    }

    private function rows(array $rows): array
    {
        $handler = new class(new SpreadsheetTableReader, new SpreadsheetHeaderDetector) extends CustomExcelHandler
        {
            public function mappedRows(array $rows): array
            {
                return iterator_to_array($this->rowsToDtos($rows, new ImportStructureResult(
                    formatSlug: 'custom_excel',
                    headerRow: 0,
                    columnMapping: ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'quantity' => 'F', 'unit_price' => 'G', 'total_price' => 'I'],
                )), false);
            }
        };

        return $handler->mappedRows($rows);
    }
}
