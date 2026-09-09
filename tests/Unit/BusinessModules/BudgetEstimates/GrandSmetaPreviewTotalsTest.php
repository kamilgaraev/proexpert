<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportFinancialSettingsResolver;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowMapper;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\GrandSmetaRuntimeBridge;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\Models\ImportSession;
use PHPUnit\Framework\TestCase;

final class GrandSmetaPreviewTotalsTest extends TestCase
{
    public function test_preview_filters_rows_and_uses_final_excel_totals(): void
    {
        $preview = $this->preview([
            new EstimateImportRowDTO(itemName: 'Section', isSection: true),
            new EstimateImportRowDTO(itemName: 'Work', quantity: 1, currentTotalAmount: 100),
            new EstimateImportRowDTO(itemName: 'Material', quantity: 1, currentTotalAmount: 60, isSubItem: true),
            new EstimateImportRowDTO(itemName: 'Empty header'),
            new EstimateImportRowDTO(itemName: 'Footer', currentTotalAmount: 500, isFooter: true),
        ], ['total_estimate_cost' => 125, 'overhead_cost' => 20, 'profit_cost' => 5]);

        self::assertCount(2, $preview->items);
        self::assertSame(2, $preview->totals['items_count']);
        self::assertSame(3, $preview->summary['rows_count']);
        self::assertEquals(125, $preview->totals['total_amount']);
        self::assertSame(2, $preview->validation['summary']['items_count']);
    }

    public function test_without_footer_resources_and_informative_rows_do_not_double_total(): void
    {
        $preview = $this->preview([
            new EstimateImportRowDTO(itemName: 'Work', currentTotalAmount: 100, quantity: 1),
            new EstimateImportRowDTO(itemName: 'Resource', currentTotalAmount: 60, isSubItem: true),
            new EstimateImportRowDTO(itemName: 'ОТ', currentTotalAmount: 30),
            new EstimateImportRowDTO(itemName: 'Correction', currentTotalAmount: -10),
        ]);

        self::assertCount(4, $preview->items);
        self::assertEquals(90, $preview->totals['total_amount']);
    }

    public function test_partial_footer_does_not_replace_the_position_total(): void
    {
        $preview = $this->preview([
            new EstimateImportRowDTO(itemName: 'Work', quantity: 2, unitPrice: 50),
        ], ['overhead_cost' => 20]);

        self::assertEquals(100, $preview->totals['total_amount']);
    }

    public function test_row_policy_keeps_sections_negative_adjustments_and_zero_price_resources(): void
    {
        $policy = new ImportRowPolicy;
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(isSection: true)));
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(currentTotalAmount: -10)));
        self::assertTrue($policy->shouldImport(new EstimateImportRowDTO(quantity: 1, isSubItem: true)));
        self::assertFalse($policy->shouldImport(new EstimateImportRowDTO));
        self::assertFalse($policy->shouldImport(new EstimateImportRowDTO(isFooter: true, currentTotalAmount: 100)));
    }

    public function test_technical_rows_are_excluded_by_the_import_mapper(): void
    {
        $mapper = $this->createMock(ImportRowMapper::class);
        $mapper->expects(self::once())->method('isTechnicalRow')->with([1, 2, 3, 4])->willReturn(true);
        $policy = new ImportRowPolicy($mapper);
        self::assertFalse($policy->shouldImport(new EstimateImportRowDTO(quantity: 4, rawData: [1, 2, 3, 4])));
    }

    public function test_position_total_does_not_add_included_overhead_twice(): void
    {
        $policy = new ImportRowPolicy;
        self::assertEquals(125, $policy->totalAmount(new EstimateImportRowDTO(currentTotalAmount: 125, overheadAmount: 20, profitAmount: 5)));
        self::assertEquals(100, $policy->totalAmount(new EstimateImportRowDTO(quantity: 2, unitPrice: 50)));
        self::assertEquals(0, $policy->totalAmount(new EstimateImportRowDTO(quantity: 2, unitPrice: 50, currentTotalAmount: 0)));
    }

    public function test_footer_resolution_keeps_the_import_override_conditions(): void
    {
        $resolver = new EstimateImportFinancialSettingsResolver;
        self::assertNull($resolver->resolveImportedTotals(['total_estimate_cost' => 125]));
        self::assertNull($resolver->resolveImportedTotals(['overhead_cost' => 20]));
        $totals = $resolver->resolveImportedTotals(['total_estimate_cost' => 125, 'overhead_cost' => 20, 'profit_cost' => 5]);
        self::assertEquals(100, $totals['total_direct_costs']);
        self::assertEquals(20, $totals['total_overhead_costs']);
        self::assertEquals(5, $totals['total_estimated_profit']);
    }

    public function test_xml_preview_does_not_reuse_footer_from_previous_excel(): void
    {
        $parser = $this->createMock(GrandSmetaParser::class);
        $parser->expects(self::never())->method('getFooterData');
        $xml = $this->createMock(GrandSmetaXMLParser::class);
        $xml->method('getStream')->willReturnCallback(function (): \Generator {
            yield new EstimateImportRowDTO(itemName: 'XML work', quantity: 1, currentTotalAmount: 80);
        });
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, $parser, $xml);
        $preview = $bridge->preview(new ImportSession, 'estimate.xml', new ImportStructureResult('grandsmeta'));
        self::assertEquals(80, $preview->totals['total_amount']);
    }

    private function preview(array $rows, array $footer = []): \App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult
    {
        $parser = $this->createMock(GrandSmetaParser::class);
        $parser->method('getStream')->willReturnCallback(function () use ($rows): \Generator {
            yield from $rows;
        });
        $parser->method('getFooterData')->willReturn($footer);
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, $parser, new GrandSmetaXMLParser);

        return $bridge->preview(new ImportSession, 'estimate.xlsx', new ImportStructureResult('grandsmeta'));
    }
}
