<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CadDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ImageDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PdfDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Cad\CadStructureExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Spreadsheet\SpreadsheetStructureExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CanonicalDocumentAdapterContractTest extends TestCase
{
    #[Test]
    public function unit_adapter_exposes_one_canonical_contract(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(DocumentUnitAdapter::class))->getMethods(),
        );

        self::assertEqualsCanonicalizing(['supports', 'createUnits', 'representation'], $methods);
        self::assertTrue(class_exists(CadStructureExtractor::class));
        self::assertTrue(class_exists(SpreadsheetStructureExtractor::class));
        $root = dirname(__DIR__, 4);
        self::assertFileDoesNotExist($root.'/app/BusinessModules/Addons/EstimateGeneration/Documents/Cad/CadDocumentAdapter.php');
        self::assertFileDoesNotExist($root.'/app/BusinessModules/Addons/EstimateGeneration/Documents/Spreadsheet/SpreadsheetDocumentAdapter.php');
    }

    #[Test]
    #[DataProvider('representationMatrix')]
    public function every_format_returns_a_complete_canonical_representation(
        string $adapter,
        DocumentUnitData $unit,
        array $expectedCapabilities,
    ): void {
        $instance = (new ReflectionClass($adapter))->newInstanceWithoutConstructor();
        self::assertInstanceOf(DocumentUnitAdapter::class, $instance);

        $representation = $instance->representation($unit);

        self::assertInstanceOf(DocumentRepresentation::class, $representation);
        self::assertSame($unit->sourceVersion, $representation->source->value);
        self::assertSame($unit->locator['visual_artifact_path'] ?? $unit->locator['artifact_path'], $representation->visualArtifactPath);
        self::assertSame($unit->locator['coordinate_space'], $representation->coordinateSpace);
        self::assertSame($expectedCapabilities, $representation->capabilities->toArray());
        self::assertIsArray($representation->nativeStructure);
    }

    public static function representationMatrix(): iterable
    {
        $version = 'sha256:'.str_repeat('a', 64);

        yield 'PDF' => [
            PdfDocumentAdapter::class,
            self::unit(DocumentUnitType::PdfPage, $version, 'pdf_page_pixels', [
                'geometry_artifact_path' => 'org-1/pdf/geometry.json',
                'geometry_artifact_sha256' => 'sha256:'.str_repeat('b', 64),
                'text_layer_status' => 'available',
                'source_bounds' => [0, 0, 200, 100],
            ]),
            ['text_spans' => 'available', 'vectors' => 'available', 'page_render' => 'available', 'source_coordinates' => 'available'],
        ];
        yield 'image' => [
            ImageDocumentAdapter::class,
            self::unit(DocumentUnitType::RasterImage, $version, 'image_pixels', [
                'ocr_spans_artifact_path' => 'org-1/image/ocr.json',
                'source_bounds' => [0, 0, 640, 480],
            ]),
            ['original_raster' => 'available', 'ocr_spans' => 'available', 'image_coordinates' => 'available'],
        ];
        yield 'CAD' => [
            CadDocumentAdapter::class,
            self::unit(DocumentUnitType::CadDrawing, $version, 'cad_model', [
                'native_structure_artifact_path' => 'org-1/cad/native.json',
                'visual_artifact_path' => 'org-1/cad/render.png',
                'source_bounds' => [0, 0, 1000, 1000],
                'native_capabilities' => array_fill_keys(['layers', 'blocks', 'polylines', 'dimensions', 'texts'], 'available'),
            ]),
            ['layers' => 'available', 'blocks' => 'available', 'polylines' => 'available', 'dimensions' => 'available', 'texts' => 'available', 'sheet_render' => 'available', 'source_coordinates' => 'available'],
        ];
        yield 'XLSX' => [
            SpreadsheetDocumentAdapter::class,
            self::unit(DocumentUnitType::SpreadsheetSheet, $version, 'spreadsheet_cells', [
                'artifact_kind' => 'spreadsheet_sheet',
                'artifact_schema_version' => 1,
                'native_structure_artifact_path' => 'org-1/xlsx/native.json',
                'visual_artifact_path' => 'org-1/xlsx/render.svg',
                'source_bounds' => [1, 1, 80, 2000],
            ]),
            ['sheets' => 'available', 'cells' => 'available', 'formulas' => 'available', 'merges' => 'available', 'table_render' => 'available', 'source_coordinates' => 'available'],
        ];
    }

    private static function unit(
        DocumentUnitType $type,
        string $version,
        string $coordinateSpace,
        array $extra = [],
    ): DocumentUnitData {
        return new DocumentUnitData($type, 1, $version, [
            'source_kind' => $type->sourceKind(),
            'source_version' => $version,
            'coordinate_space' => $coordinateSpace,
            'artifact_path' => 'org-1/documents/unit.bin',
            'artifact_bytes' => 10,
            'artifact_sha256' => 'sha256:'.str_repeat('c', 64),
            'artifact_source_version' => 'sha256:'.str_repeat('c', 64),
            'content_type' => 'application/octet-stream',
            ...$extra,
        ]);
    }
}
