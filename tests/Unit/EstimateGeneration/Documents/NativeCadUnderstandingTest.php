<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSemanticUnderstandingSummarizer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeCadUnderstanding;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use PHPUnit\Framework\TestCase;

final class NativeCadUnderstandingTest extends TestCase
{
    public function test_a_certified_model_dimension_is_not_a_room_area_or_a_complete_takeoff(): void
    {
        $summary = $this->summary();
        self::assertSame('quantity_source', $summary['role_for_estimation']);
        $capabilities = $summary['extracted_capabilities'];
        self::assertTrue($capabilities['has_dimensions']);
        self::assertTrue($capabilities['has_quantities']);
        self::assertSame(1, $capabilities['accepted_quantity_claims']);
        self::assertFalse($capabilities['has_room_areas']);
        self::assertFalse($capabilities['has_quantity_takeoffs']);
        self::assertFalse($capabilities['has_pdf_geometry']);
        self::assertFalse($capabilities['requires_manual_review']);
        $combined = (new DocumentSemanticUnderstandingSummarizer)->summarize([$this->payload($summary)]);
        self::assertSame('drawing', $combined['document_understanding']['document_type']);
        self::assertSame('quantity_source', $combined['document_understanding']['role_for_estimation']);
        self::assertArrayNotHasKey('analysis_roles_complete', $combined);
    }

    public function test_partial_parsing_unknown_units_or_unaccepted_dimensions_require_review(): void
    {
        foreach ([['structure' => 'partial'], ['unitStatus' => 'unknown'], ['layout' => 'Layout1'],
            ['extra' => ['block' => 'door']], ['extra' => ['transform' => [1, 0]]], ['warnings' => [['code' => 'geometry_incomplete']]]] as $arguments) {
            $summary = $this->summary(...$arguments);
            self::assertSame('needs_review', $summary['role_for_estimation']);
            self::assertTrue($summary['extracted_capabilities']['requires_manual_review']);
            self::assertFalse($summary['extracted_capabilities']['has_room_areas']);
        }
        self::assertSame(0, $this->summary(unitStatus: 'unknown')['extracted_capabilities']['accepted_quantity_claims']);
        self::assertSame(0, $this->summary(layout: 'Layout1')['extracted_capabilities']['accepted_quantity_claims']);
    }

    public function test_a_complete_drawing_without_supported_dimensions_is_context_only(): void
    {
        $summary = $this->summary(dimensions: []);
        self::assertSame('context_document', $summary['role_for_estimation']);
        self::assertFalse($summary['extracted_capabilities']['has_dimensions']);
        self::assertFalse($summary['extracted_capabilities']['has_quantities']);
        self::assertSame(0, $summary['extracted_capabilities']['accepted_quantity_claims']);
    }

    public function test_legacy_cad_or_mixed_native_payloads_do_not_acquire_a_complete_understanding(): void
    {
        $summarizer = new DocumentSemanticUnderstandingSummarizer;
        self::assertArrayNotHasKey('document_understanding', $summarizer->summarize([['schema_version' => 1, 'source_kind' => 'cad']]));
        self::assertArrayNotHasKey('document_understanding', $summarizer->summarize([$this->payload($this->summary()), []]));
        $wrongContract = $this->payload($this->summary());
        $wrongContract['source_kind'] = 'spreadsheet';
        self::assertArrayNotHasKey('document_understanding', $summarizer->summarize([$wrongContract]));
    }

    private function payload(array $summary): array
    {
        return ['schema_version' => 1, 'source_kind' => 'cad', 'native_understanding' => $summary];
    }

    private function summary(string $structure = 'available', string $unitStatus = 'confirmed', string $layout = 'Model',
        array $extra = [], array $warnings = [], ?array $dimensions = null): array
    {
        $context = new DocumentUnitExecutionContext(1, 7, 9, 11, 13, DocumentUnitType::CadDrawing, 1,
            'sha256:'.str_repeat('a', 64), [], 'source.dxf', 'application/dxf', 'source.dxf', 'claim-token', 1, 0, 'draft', 17);
        $publication = (new NativeNumericFactFactory)->cad($context, ['unit_status' => $unitStatus,
            'source_unit' => $unitStatus === 'confirmed' ? 'mm' : null,
            'dimensions' => $dimensions ?? [['handle' => 'A1', 'dimension_type' => 0, 'measurement' => '3000',
                'measurement_space' => 'entity_coordinates', 'layout' => $layout, ...$extra]]]);

        return (new NativeCadUnderstanding)->summarize($publication, ['status' => $structure, 'unit_status' => $unitStatus], $warnings);
    }
}
