<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentCoordinateTransform;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentManifestNeedsReview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationCapabilities;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationResourceLimits;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\Support\DatabaseLessTestCase;

final class DocumentRepresentationMatrixTest extends DatabaseLessTestCase
{
    #[Test]
    public function real_vector_pdf_fixture_is_parsed_and_coordinates_round_trip(): void
    {
        $path = $this->realFixture('vector-pdf/input.pdf');
        $document = (new Parser)->parseFile($path);
        self::assertNotEmpty($document->getPages());
        self::assertNotSame('', trim($document->getText()));

        $transform = DocumentCoordinateTransform::fromBounds([0.0, 0.0, 595.0, 842.0]);
        $source = [297.5, 421.0];
        self::assertEqualsWithDelta($source, $transform->toSource($transform->toNormalized($source)), 0.000000001);
    }

    #[Test]
    public function real_dxf_fixture_is_processed_by_the_bounded_cad_runtime(): void
    {
        $root = dirname(__DIR__, 4);
        $result = (new CadConversionRuntime(
            'python',
            $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py',
        ))->extract($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dxf');

        self::assertNotEmpty($result->entities);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->sourceFingerprint);
    }

    #[Test]
    public function real_png_bytes_are_decoded_with_actual_pixel_bounds(): void
    {
        $image = imagecreatetruecolor(37, 23);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info);
        self::assertSame([37, 23], [$info[0], $info[1]]);
    }

    #[Test]
    public function real_xlsx_container_is_parsed_by_the_bounded_production_extractor(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Sheet')->setCellValue('A1', 'Помещение')->setCellValue('B1', 12.5);
        $path = tempnam(sys_get_temp_dir(), 'most-real-xlsx-');
        self::assertIsString($path);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        try {
            $document = new \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
            $document->id = 1;
            $document->organization_id = 1;
            $document->project_id = 1;
            $document->session_id = 1;
            $document->filename = 'real.xlsx';
            $document->source_version = 'sha256:'.hash_file('sha256', $path);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($document, $path);

            $native = $result->pages[0]->rawPayload['native_structure'];
            self::assertSame('available', $native['status']);
            self::assertContains('xlsx:sheet:Sheet!A1', $native['native_reference_registry']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function unavailable_native_capability_is_typed_and_requires_review(): void
    {
        $capabilities = DocumentRepresentationCapabilities::fromArray('cad', [
            'layers' => 'available',
            'blocks' => 'unavailable:dwg_blocks_not_supported',
            'polylines' => 'available',
            'dimensions' => 'unavailable:dwg_dimensions_not_supported',
            'texts' => 'available',
            'sheet_render' => 'available',
            'source_coordinates' => 'available',
        ]);

        self::assertSame('blocks', $capabilities->limitations()[0]->capability);
        self::assertSame('dwg_blocks_not_supported', $capabilities->limitations()[0]->reason);

        try {
            $capabilities->assertAvailable('blocks');
            self::fail('Unavailable native capability must require review.');
        } catch (DocumentManifestNeedsReview $exception) {
            self::assertSame('document_native_capability_unavailable', $exception->safeCode);
            self::assertSame(['capability' => 'blocks', 'reason' => 'dwg_blocks_not_supported'], $exception->safeContext);
        }
    }

    #[Test]
    #[DataProvider('limitViolations')]
    public function every_resource_limit_is_enforced(array $usage, string $safeCode): void
    {
        $this->expectException(DocumentManifestNeedsReview::class);
        $this->expectExceptionMessage($safeCode);

        (new DocumentRepresentationResourceLimits(
            maxPages: 2,
            maxObjects: 3,
            maxBytes: 4,
            maxPeakMemoryBytes: 5,
            maxDurationMs: 6,
        ))->assertWithin($usage);
    }

    public static function limitViolations(): iterable
    {
        yield 'pages' => [['pages' => 3, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_page_limit_exceeded'];
        yield 'objects' => [['pages' => 1, 'objects' => 4, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_object_limit_exceeded'];
        yield 'bytes' => [['pages' => 1, 'objects' => 0, 'bytes' => 5, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_size_limit_exceeded'];
        yield 'memory' => [['pages' => 1, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 6, 'duration_ms' => 1], 'document_representation_memory_limit_exceeded'];
        yield 'timeout' => [['pages' => 1, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 7], 'document_representation_timeout_exceeded'];
    }

    private function realFixture(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/acceptance-candidate/independent-v1/cases/'.$relative;
        self::assertFileExists($path);

        return $path;
    }
}
