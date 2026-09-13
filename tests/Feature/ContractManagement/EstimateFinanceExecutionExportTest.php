<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport;
use Tests\TestCase;

final class EstimateFinanceExecutionExportTest extends TestCase
{
    public function test_execution_export_preserves_report_values_unknowns_and_source_identifiers(): void
    {
        $total = ['revenue' => '120.01', 'cost' => null, 'difference' => null, 'known_revenue' => '120.01', 'known_cost' => '30.00',
            'revenue_unpriced_count' => 0, 'cost_unpriced_count' => 1, 'unknown_direction_count' => 0];
        $report = ['name' => '=1+1', 'rows' => [['key' => 'i:10', 'name' => 'Работа', 'unit' => 'м2']],
            'sections' => [['id' => 7, 'name' => 'Раздел']], 'contracts' => [['id' => 1, 'number' => '001']],
            'execution' => ['available' => true, 'summary' => ['totals' => ['RUB' => $total],
                'positions' => [['target_key' => 'i:10', 'currencies' => ['RUB' => $total]]],
                'sections' => [7 => ['RUB' => $total]],
                'contract_quantities' => [['contract_id' => 1, 'target_key' => 'i:10', 'currency' => 'RUB', 'planned_quantity' => '5',
                    'accepted_quantity' => '2', 'remaining_quantity' => '3', 'overrun_quantity' => '0']]],
                'documents' => [['id' => 9, 'contract_id' => 1, 'number' => 'А-9', 'date' => '2026-09-12', 'side' => 'revenue', 'currency' => 'RUB',
                    'amount_without_vat' => null, 'amount_with_vat' => '150.01', 'estimate_amount_with_vat' => '120.01', 'unallocated_amount_with_vat' => '30', 'needs_review' => false, 'status' => 'signed']],
                'rows' => [['title' => '=HYPERLINK("https://example.com")', 'act_id' => 9, 'source_type' => 'act_line', 'source_id' => 18, 'contract_id' => 1,
                    'side' => 'revenue', 'quantity' => '2', 'currency' => 'RUB', 'amount_without_vat' => null, 'amount_with_vat' => '120.01', 'allocation_key' => 'stable-key', 'condition_version' => 4]]]];
        $book = app(EstimateFinanceExport::class)->workbook([$report], 'with_vat', [], 'execution');
        try {
            self::assertSame(6, $book->getSheetCount());
            self::assertSame('Разница с НДС', $book->getSheet(0)->getCell('E1')->getValue());
            self::assertEquals('120.01', $book->getSheet(0)->getCell('C2')->getValue());
            self::assertSame('', $book->getSheet(0)->getCell('D2')->getValue());
            self::assertSame('', $book->getSheet(0)->getCell('E2')->getValue());
            self::assertEquals('120.01', $book->getSheet(1)->getCell('D2')->getValue());
            self::assertEquals('120.01', $book->getSheet(2)->getCell('D2')->getValue());
            self::assertEquals('3', $book->getSheet(3)->getCell('H2')->getValue());
            self::assertSame('001', $book->getSheet(3)->getCell('C2')->getValue());
            self::assertSame('', $book->getSheet(4)->getCell('H2')->getValue());
            self::assertEquals('30', $book->getSheet(4)->getCell('K2')->getValue());
            self::assertSame('Подписан', $book->getSheet(4)->getCell('L2')->getValue());
            self::assertSame('stable-key', $book->getSheet(5)->getCell('L2')->getValue());
            self::assertEquals(4, $book->getSheet(5)->getCell('M2')->getValue());
            self::assertSame('s', $book->getSheet(5)->getCell('B2')->getDataType());
            self::assertSame('s', $book->getSheet(0)->getCell('A2')->getDataType());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_unavailable_execution_is_explicit_and_does_not_export_embedded_facts(): void
    {
        $book = app(EstimateFinanceExport::class)->workbook([['name' => 'Смета', 'execution' => ['available' => false,
            'rows' => [['title' => 'Закрытые данные']], 'documents' => [['number' => 'Закрытый акт']]]]], 'without_vat', [], 'execution');
        try {
            self::assertSame('Нет доступа к актам', $book->getSheet(0)->getCell('L2')->getValue());
            self::assertSame('', $book->getSheet(0)->getCell('C2')->getValue());
            self::assertSame(1, $book->getSheet(4)->getHighestRow());
            self::assertSame(1, $book->getSheet(5)->getHighestRow());
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
