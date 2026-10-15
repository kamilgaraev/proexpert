<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\FormulaAwarenessService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class FormulaAwarenessServiceTest extends TestCase
{
    private mixed $previousApplication;
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 4));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 4).'/lang'), 'ru'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
    }

    public function test_modern_final_totals_include_source_charges_in_both_signs(): void
    {
        foreach ([1, -1] as $sign) {
            $rows = [$this->modernRow($sign)];
            (new FormulaAwarenessService)->annotate($rows);
            self::assertFalse($rows[0]['has_math_mismatch']);
            self::assertSame([], $rows[0]['warnings']);
            self::assertSame(250.0 * $sign, $rows[0]['math_expected_amount']);
            self::assertSame('quantity_price_with_charges', $rows[0]['math_comparison_basis']);
        }
    }

    public function test_actual_modern_mismatch_stays_visible_with_the_full_expected_total(): void
    {
        $row = $this->modernRow(1);
        $row['current_total_amount'] = 260.0;
        $rows = [$row];
        (new FormulaAwarenessService)->annotate($rows);
        self::assertTrue($rows[0]['has_math_mismatch']);
        self::assertSame(250.0, $rows[0]['math_expected_amount']);
        self::assertStringContainsString('260,00', $rows[0]['warnings'][0]);
        self::assertStringContainsString('250,00', $rows[0]['warnings'][0]);
        foreach (['quantity', 'unit_price', 'current_total_amount', 'overhead_amount', 'profit_amount', 'raw_data'] as $field) {
            self::assertSame($row[$field], $rows[0][$field]);
        }
    }

    public function test_unmarked_rows_keep_the_quantity_price_check_and_do_not_guess_charges(): void
    {
        $rows = [$this->modernRow(1)];
        unset($rows[0]['raw_data']);
        unset($rows[0]['overhead_amount'], $rows[0]['profit_amount']);
        $rows[0]['overhead_rate'] = 15;
        $rows[0]['profit_rate'] = 10;
        (new FormulaAwarenessService)->annotate($rows);
        self::assertTrue($rows[0]['has_math_mismatch']);
        self::assertSame(200.0, $rows[0]['math_expected_amount']);
        self::assertSame('quantity_price', $rows[0]['math_comparison_basis']);
    }

    public function test_explicit_source_charges_also_apply_to_other_spreadsheet_formats(): void
    {
        $rows = [$this->modernRow(1)];
        unset($rows[0]['raw_data']);
        (new FormulaAwarenessService)->annotate($rows);
        self::assertFalse($rows[0]['has_math_mismatch']);
        self::assertSame(250.0, $rows[0]['math_expected_amount']);
        self::assertSame('quantity_price_with_charges', $rows[0]['math_comparison_basis']);
    }

    public function test_index_check_accounts_for_rounded_source_base_totals_but_keeps_real_errors(): void
    {
        $row = $this->modernRow(1);
        $row = array_replace($row, ['quantity' => 1, 'unit_price' => 144.21, 'current_total_amount' => 144.21, 'overhead_amount' => 0, 'profit_amount' => 0, 'base_unit_price' => 10.01, 'price_index' => 14.4]);
        $row['raw_data']['base_total_amount'] = 10.01;
        $rows = [$row];
        (new FormulaAwarenessService)->annotate($rows);
        self::assertFalse($rows[0]['has_math_mismatch']);
        $row['unit_price'] += 5;
        $row['current_total_amount'] += 5;
        $rows = [$row];
        (new FormulaAwarenessService)->annotate($rows);
        self::assertTrue($rows[0]['has_math_mismatch']);
        self::assertStringContainsString('индекс', $rows[0]['warnings'][0]);
    }

    public function test_informational_rows_are_not_quantity_formulas_but_resource_children_still_are(): void
    {
        $rows = [
            ['item_name' => 'ОТ', 'is_sub_item' => true, 'quantity' => 0, 'unit_price' => 0, 'current_total_amount' => 70, 'has_math_mismatch' => false, 'warnings' => []],
            ['item_name' => 'Неучтённая работа', 'is_not_accounted' => true, 'quantity' => 0, 'unit_price' => 0, 'current_total_amount' => 50, 'has_math_mismatch' => false, 'warnings' => []],
            ['item_name' => 'Ресурс', 'code' => '01.2', 'is_sub_item' => true, 'quantity' => 2, 'unit_price' => 10, 'current_total_amount' => 25, 'has_math_mismatch' => false, 'warnings' => []],
        ];
        (new FormulaAwarenessService)->annotate($rows);
        foreach ([0, 1] as $index) {
            self::assertTrue($rows[$index]['is_informative']);
            self::assertFalse($rows[$index]['has_math_mismatch']);
            self::assertSame([], $rows[$index]['warnings']);
            self::assertNull($rows[$index]['math_expected_amount']);
        }
        self::assertFalse($rows[2]['is_informative']);
        self::assertTrue($rows[2]['has_math_mismatch']);
        self::assertSame(20.0, $rows[2]['math_expected_amount']);
    }

    public function test_allocated_unit_price_is_not_an_independent_index_control(): void
    {
        $row = ['item_name' => 'Материал', 'quantity' => 1, 'unit_price' => 201, 'current_total_amount' => 201, 'base_unit_price' => 100, 'price_index' => 2, 'math_price_derived_from_total' => true];
        self::assertSame([], (new FormulaAwarenessService)->validate($row));
        $row['math_price_derived_from_total'] = false;
        self::assertCount(1, (new FormulaAwarenessService)->validate($row));
        $row['math_price_derived_from_total'] = true;
        $row['current_total_amount'] = 250;
        self::assertCount(1, (new FormulaAwarenessService)->validate($row));
    }

    public function test_zero_direct_price_can_have_explicit_source_charges(): void
    {
        $rows = [$this->modernRow(1)];
        $rows[0]['unit_price'] = 0;
        $rows[0]['current_total_amount'] = 50;
        (new FormulaAwarenessService)->annotate($rows);
        self::assertFalse($rows[0]['has_math_mismatch']);
        self::assertSame(50.0, $rows[0]['math_expected_amount']);
    }

    private function modernRow(int $sign): array
    {
        return [
            'item_name' => 'Работа', 'quantity' => 2 * $sign, 'unit_price' => 100,
            'current_total_amount' => 250.0 * $sign, 'overhead_amount' => 30.0 * $sign, 'profit_amount' => 20.0 * $sign,
            'raw_data' => ['financial_structure' => ['layout' => 'grand_modern_2020']],
            'has_math_mismatch' => false, 'warnings' => [],
        ];
    }
}
