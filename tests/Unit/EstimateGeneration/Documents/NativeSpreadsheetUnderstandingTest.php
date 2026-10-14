<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSemanticUnderstandingSummarizer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeSpreadsheetUnderstanding;
use PHPUnit\Framework\TestCase;

final class NativeSpreadsheetUnderstandingTest extends TestCase
{
    public function test_verified_lengths_support_dimensions_without_promoting_derived_room_area_or_pdf_geometry(): void
    {
        $summary = $this->summary([['room:office', 'length', '5', 'м'], ['room:office', 'width', '10', 'м']]);
        self::assertSame('quantity_source', $summary['role_for_estimation']);
        $capabilities = $summary['extracted_capabilities'];
        self::assertTrue($capabilities['has_dimensions']);
        self::assertFalse($capabilities['has_room_areas']);
        self::assertFalse($capabilities['has_pdf_geometry']);
        self::assertFalse($capabilities['has_quantity_takeoffs']);
        self::assertFalse($capabilities['requires_manual_review']);
        self::assertSame(2, $capabilities['accepted_quantity_claims']);
        $combined = (new DocumentSemanticUnderstandingSummarizer)->summarize([$this->payload($summary), $this->payload($summary)]);
        self::assertSame(2, $combined['pages_checked']);
        self::assertSame(4, $combined['document_understanding']['extracted_capabilities']['accepted_quantity_claims']);
        self::assertArrayNotHasKey('analysis_roles_complete', $combined, 'Native processing must not request a model-role completion gate.');
    }

    public function test_explicit_room_area_is_distinct_from_area_on_another_entity(): void
    {
        self::assertTrue($this->summary([['room:office', 'area', '25.97', 'м²']])['extracted_capabilities']['has_room_areas']);
        self::assertFalse($this->summary([['roof:1', 'area', '25.97', 'м²']])['extracted_capabilities']['has_room_areas']);
    }

    public function test_a_native_room_name_is_context_without_a_measurement_or_area(): void
    {
        $summary = $this->summary([['room:office', 'room_name', 'Офис', '']]);
        self::assertSame('context_document', $summary['role_for_estimation']);
        self::assertSame(0, $summary['extracted_capabilities']['accepted_quantity_claims']);
        self::assertFalse($summary['extracted_capabilities']['has_quantities']);
        self::assertFalse($summary['extracted_capabilities']['has_dimensions']);
        self::assertFalse($summary['extracted_capabilities']['has_room_areas']);
        self::assertFalse($summary['extracted_capabilities']['requires_manual_review']);
    }

    public function test_a_formula_unknown_parameter_or_partial_sheet_requires_review_without_an_accepted_quantity(): void
    {
        $formula = $this->summary([['room:office', 'length', '5', 'м']], formula: '=2+3');
        self::assertSame('needs_review', $formula['role_for_estimation']);
        self::assertFalse($formula['extracted_capabilities']['has_quantities']);
        self::assertSame(0, $formula['extracted_capabilities']['accepted_quantity_claims']);
        $unknown = $this->summary([['room:office', 'unknown_parameter', '5', 'м']]);
        self::assertTrue($unknown['extracted_capabilities']['requires_manual_review']);
        self::assertFalse($unknown['extracted_capabilities']['has_dimensions']);
        $wrongUnit = $this->summary([['room:office', 'length', '5', 'м²']]);
        self::assertTrue($wrongUnit['extracted_capabilities']['requires_manual_review']);
        self::assertFalse($wrongUnit['extracted_capabilities']['has_dimensions']);
        $partial = $this->summary([['room:office', 'length', '5', 'м']], 'partial');
        self::assertSame('needs_review', $partial['role_for_estimation']);
        self::assertTrue($partial['extracted_capabilities']['requires_manual_review']);
        $combined = (new DocumentSemanticUnderstandingSummarizer)->summarize([$this->payload($partial)]);
        self::assertSame('needs_review', $combined['document_understanding']['role_for_estimation']);
    }

    public function test_untyped_legacy_or_mixed_payloads_do_not_acquire_a_native_understanding(): void
    {
        $summarizer = new DocumentSemanticUnderstandingSummarizer;
        self::assertArrayNotHasKey('document_understanding', $summarizer->summarize([
            ['schema_version' => 1, 'source_kind' => 'spreadsheet', 'native_structure' => []],
        ]));
        $summary = $this->summary([['room:office', 'length', '5', 'м']]);
        self::assertArrayNotHasKey('document_understanding', $summarizer->summarize([$this->payload($summary), []]));
        self::assertSame('context_document', (new NativeSpreadsheetUnderstanding)->summarize(null, 'available')['role_for_estimation']);
    }

    private function payload(array $summary): array
    {
        return ['schema_version' => 1, 'source_kind' => 'spreadsheet', 'native_understanding' => $summary];
    }

    private function summary(array $rows, string $status = 'available', ?string $formula = null): array
    {
        $headers = [];
        foreach (['entity_key', 'parameter', 'value', 'unit'] as $index => $value) {
            $headers[] = ['address' => chr(65 + $index).'1', 'value' => $value];
        }
        $cells = [];
        foreach ($rows as $row => $values) {
            foreach ($values as $column => $value) {
                $cells[] = ['address' => chr(65 + $column).($row + 2), 'value' => $value, 'raw_value' => $value,
                    'formula' => $column === 2 ? $formula : null];
            }
        }
        $context = new DocumentUnitExecutionContext(1, 7, 9, 11, 13, DocumentUnitType::SpreadsheetSheet, 1,
            'sha256:'.str_repeat('a', 64), [], 'source.xlsx', 'application/octet-stream', 'source.xlsx', 'claim-token', 1, 0, 'draft', 17);
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Размеры', 'header_cells' => $headers, 'cells' => $cells]);

        return (new NativeSpreadsheetUnderstanding)->summarize($publication, $status);
    }
}
