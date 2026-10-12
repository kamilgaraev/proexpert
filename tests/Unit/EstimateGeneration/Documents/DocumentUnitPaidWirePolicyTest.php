<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPaidWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use PHPUnit\Framework\TestCase;

final class DocumentUnitPaidWirePolicyTest extends TestCase
{
    public function test_only_locally_parsed_artifacts_bypass_the_paid_wire(): void
    {
        self::assertFalse(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::CadDrawing, []));
        self::assertFalse(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::TextPage, ['content_type' => 'text/plain']));
        self::assertFalse(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::PdfPage, ['content_type' => 'application/vnd.most.pdf-page+json']));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::PdfPage, ['content_type' => 'image/png']));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::RasterImage, ['content_type' => 'image/jpeg']));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, ['content_type' => 'image/png']));
    }

    public function test_spreadsheet_json_requires_the_exact_schema_and_source_kind(): void
    {
        $locator = ['source_kind' => 'spreadsheet', 'content_type' => 'application/json', 'artifact_kind' => 'spreadsheet_sheet', 'artifact_schema_version' => 1];
        self::assertFalse(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, $locator));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, array_replace($locator, ['artifact_schema_version' => '1'])));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, array_replace($locator, ['source_kind' => 'image'])));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, array_replace($locator, ['artifact_kind' => 'unknown'])));
        $legacy = ['content_type' => 'application/vnd.most.spreadsheet-sheet+json'];
        self::assertFalse(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, $legacy));
        self::assertTrue(DocumentUnitPaidWirePolicy::requiresPaidWire(DocumentUnitType::SpreadsheetSheet, $legacy + ['artifact_kind' => null]));
    }
}
