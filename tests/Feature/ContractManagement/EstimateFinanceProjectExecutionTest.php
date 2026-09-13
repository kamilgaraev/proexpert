<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceProjectExecution;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport;
use Tests\TestCase;

final class EstimateFinanceProjectExecutionTest extends TestCase
{
    public function test_shared_act_and_repeated_report_are_counted_once_with_estimate_breakdown(): void
    {
        $first = $this->report(1, '120.01');
        $second = $this->report(2, '79.99');
        $result = app(EstimateFinanceProjectExecution::class)->combine([$first, $second, $first], 'with_vat', true);
        self::assertTrue($result['available']);
        self::assertCount(2, $result['rows']);
        self::assertCount(1, $result['documents']);
        self::assertSame('200.00', $result['summary']['totals']['RUB']['revenue']);
        self::assertSame(2, $result['summary']['totals']['RUB']['sources_count']);
        self::assertSame('250.00', $result['documents'][0]['amount_with_vat']);
        self::assertSame('200.00', $result['documents'][0]['estimate_amount_with_vat']);
        self::assertSame('50.00', $result['documents'][0]['unallocated_amount_with_vat']);
        self::assertCount(2, $result['documents'][0]['estimate_amounts']);
        self::assertSame('120.01', $result['documents'][0]['estimate_amounts'][0]['amount_with_vat']);
        self::assertSame('79.99', $result['documents'][0]['estimate_amounts'][1]['amount_with_vat']);
        self::assertCount(2, $result['summary']['contract_quantities']);
        self::assertSame('4.00000000', $result['summary']['contract_quantities'][0]['remaining_quantity']);
        self::assertSame('120.01', $result['summary']['sections'][1]['RUB']['revenue']);
        $unknown = app(EstimateFinanceProjectExecution::class)->combine([$first, $second], 'without_vat', true);
        self::assertNull($unknown['summary']['totals']['RUB']['revenue']);
        self::assertNull($unknown['summary']['totals']['RUB']['difference']);
        self::assertSame(2, $unknown['summary']['totals']['RUB']['revenue_unpriced_count']);
    }

    public function test_unavailable_or_empty_project_does_not_fabricate_execution(): void
    {
        $service = app(EstimateFinanceProjectExecution::class);
        self::assertSame(['available' => false, 'rows' => null, 'documents' => null], $service->combine([$this->report(1, '120')], 'with_vat', false));
        self::assertFalse($service->combine([['execution' => ['available' => false]]], 'with_vat', true)['available']);
        $empty = $service->combine([], 'with_vat', true);
        self::assertTrue($empty['available']);
        self::assertSame([], $empty['summary']['totals']);
        self::assertSame([], $empty['documents']);
    }

    public function test_project_workbook_exports_shared_document_once_and_uses_project_summary(): void
    {
        $service = app(EstimateFinanceProjectExecution::class);
        $reports = [$this->report(1, '120.01'), $this->report(2, '79.99')];
        $project = $service->combine($reports, 'with_vat', true);
        foreach ($reports as &$report) {
            $report['execution']['summary'] = $service->combine([$report], 'with_vat', true)['summary'];
        }
        unset($report);
        $book = app(EstimateFinanceExport::class)->workbook($reports, 'with_vat', [], 'execution', $project);
        try {
            self::assertSame(2, $book->getSheet(4)->getHighestRow());
            self::assertSame('Смета 1; Смета 2', $book->getSheet(4)->getCell('A2')->getValue());
            self::assertEquals('250.00', $book->getSheet(4)->getCell('I2')->getValue());
            self::assertEquals('200.00', $book->getSheet(4)->getCell('J2')->getValue());
            self::assertEquals('50.00', $book->getSheet(4)->getCell('K2')->getValue());
            self::assertSame('Итого по проекту', $book->getSheet(0)->getCell('A4')->getValue());
            self::assertEquals($project['summary']['totals']['RUB']['revenue'], $book->getSheet(0)->getCell('C4')->getValue());
            self::assertSame(3, $book->getSheet(5)->getHighestRow());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function report(int $id, string $amount): array
    {
        return ['estimate_id' => $id, 'name' => 'Смета '.$id, 'contracts' => [['id' => 1, 'number' => 'Д-1']],
            'rows' => [['key' => 'i:'.$id, 'section_id' => $id, 'parent_key' => null, 'name' => 'Работа '.$id, 'unit' => 'м2',
                'allocations' => [['key' => 'allocation-'.$id, 'contract_id' => 1, 'target_key' => 'i:'.$id, 'currency' => 'RUB', 'quantity' => '5']]]],
            'sections' => [['id' => $id, 'parent_section_id' => null, 'name' => 'Раздел '.$id]],
            'execution' => ['available' => true,
                'rows' => [['act_id' => 10, 'source_type' => 'act_line', 'source_id' => $id, 'item_id' => $id, 'contract_id' => 1,
                    'title' => 'Работа '.$id, 'allocation_key' => 'allocation-'.$id, 'condition_version' => 1,
                    'currency' => 'RUB', 'quantity' => '1', 'side' => 'revenue', 'amount_with_vat' => $amount, 'amount_without_vat' => null]],
                'documents' => [['id' => 10, 'contract_id' => 1, 'number' => 'А-10', 'date' => '2026-09-12', 'currency' => 'RUB', 'side' => 'revenue', 'status' => 'approved', 'needs_review' => false,
                    'amount_without_vat' => null, 'amount_with_vat' => '250.00', 'estimate_amount_with_vat' => $amount, 'unallocated_amount_with_vat' => '50.00']]]];
    }
}
