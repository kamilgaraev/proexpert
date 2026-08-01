<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CadDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentManifestNeedsReview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ImageDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PdfDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArtifactDocumentUnitDetectorTest extends TestCase
{
    #[Test]
    public function unit_contract_rejects_missing_pinned_artifact_provenance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentUnitData(DocumentUnitType::PdfPage, 1, 'sha256:'.str_repeat('a', 64), [
            'source_kind' => 'pdf',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'coordinate_space' => 'pdf_page_pixels',
        ]);
    }

    #[Test]
    #[DataProvider('cadPriorityMatrix')]
    public function cad_claims_dwg_before_image_for_conflicting_mime_and_extension(
        string $filename,
        string $mimeType,
    ): void {
        $document = new EstimateGenerationDocument([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'storage_path' => 'org-10/source/plan.dwg',
            'file_size_bytes' => 256,
            'meta' => ['storage_version_id' => 'dwg-v1'],
        ]);

        $units = $this->detector()->detect($document, 'sha256:'.str_repeat('f', 64));

        self::assertSame([DocumentUnitType::CadDrawing], array_column($units, 'type'));
    }

    /** @return array<string, array{string, string}> */
    public static function cadPriorityMatrix(): array
    {
        return [
            'DWG MIME with image extension' => ['plan.png', 'image/vnd.dwg'],
            'DWG extension with image MIME' => ['plan.dwg', 'image/png'],
        ];
    }

    #[Test]
    public function ifc_is_rejected_until_a_dedicated_provider_is_available(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'model.ifc',
            'mime_type' => 'application/x-step',
            'storage_path' => 'org-10/source/model.ifc',
            'file_size_bytes' => 256,
            'meta' => ['storage_version_id' => 'ifc-v1'],
        ]);

        try {
            $this->detector()->detect($document, 'sha256:'.str_repeat('a', 64));
            self::fail('IFC must remain unsupported.');
        } catch (DocumentManifestNeedsReview $exception) {
            self::assertSame('document_source_kind_unsupported', $exception->safeCode);
        }
    }

    #[Test]
    #[DataProvider('documentMatrix')]
    public function every_supported_document_kind_has_pinned_provenance_and_reproducible_indexes(
        EstimateGenerationDocument $document,
        string $sourceVersion,
        DocumentUnitType $type,
        string $sourceKind,
        string $coordinateSpace,
        array $indexes,
    ): void {
        $units = $this->detector()->detect($document, $sourceVersion);

        self::assertSame($indexes, array_column($units, 'index'));
        self::assertSame(array_fill(0, count($indexes), $type), array_column($units, 'type'));

        foreach ($units as $unit) {
            self::assertInstanceOf(DocumentUnitData::class, $unit);
            self::assertSame($sourceKind, $unit->locator['source_kind']);
            self::assertSame($sourceVersion, $unit->locator['source_version']);
            self::assertSame($coordinateSpace, $unit->locator['coordinate_space']);
            self::assertMatchesRegularExpression('/^org-10\//', $unit->locator['artifact_path']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $unit->locator['artifact_sha256']);
            self::assertNotSame('', $unit->locator['artifact_version_id']);
            self::assertSame(
                sprintf('%s:%d:%s', $type->value, $unit->index, $sourceVersion),
                $unit->identity(),
            );
        }
    }

    /**
     * @return array<string, array{0: EstimateGenerationDocument, 1: string, 2: DocumentUnitType, 3: string, 4: string, 5: list<int>}>
     */
    public static function documentMatrix(): array
    {
        return [
            'PDF' => [
                new EstimateGenerationDocument(['filename' => 'plan.pdf', 'mime_type' => 'application/pdf']),
                'sha256:'.str_repeat('a', 64),
                DocumentUnitType::PdfPage,
                'pdf',
                'pdf_page_pixels',
                [1],
            ],
            'image' => [
                new EstimateGenerationDocument([
                    'filename' => 'photo.png',
                    'mime_type' => 'image/png',
                    'storage_path' => 'org-10/source/photo.png',
                    'file_size_bytes' => 128,
                    'meta' => ['storage_version_id' => 'image-v1'],
                ]),
                'sha256:'.str_repeat('b', 64),
                DocumentUnitType::RasterImage,
                'image',
                'image_pixels',
                [1],
            ],
            'DWG' => [
                new EstimateGenerationDocument([
                    'filename' => 'plan.dwg',
                    'mime_type' => 'image/vnd.dwg',
                    'storage_path' => 'org-10/source/plan.dwg',
                    'file_size_bytes' => 256,
                    'meta' => ['storage_version_id' => 'dwg-v1'],
                ]),
                'sha256:'.str_repeat('c', 64),
                DocumentUnitType::CadDrawing,
                'cad',
                'cad_model',
                [1],
            ],
            'DXF' => [
                new EstimateGenerationDocument([
                    'filename' => 'plan.dxf',
                    'mime_type' => 'application/dxf',
                    'storage_path' => 'org-10/source/plan.dxf',
                    'file_size_bytes' => 256,
                    'meta' => ['storage_version_id' => 'dxf-v1'],
                ]),
                'sha256:'.str_repeat('d', 64),
                DocumentUnitType::CadDrawing,
                'cad',
                'cad_model',
                [1],
            ],
            'XLSX' => [
                new EstimateGenerationDocument([
                    'filename' => 'estimate.xlsx',
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]),
                'sha256:'.str_repeat('e', 64),
                DocumentUnitType::SpreadsheetSheet,
                'spreadsheet',
                'spreadsheet_cells',
                [1, 2],
            ],
        ];
    }

    private function detector(): ArtifactDocumentUnitDetector
    {
        $storage = new class implements DocumentSourceManifestStorage
        {
            public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
            {
                $stream = tmpfile();
                fwrite($stream, 'source');
                rewind($stream);

                return new SeekableDocumentSource($stream, 6);
            }

            public function put(
                EstimateGenerationDocument $document,
                string $sourceVersion,
                DocumentUnitType $type,
                int $index,
                string $content,
                string $contentType = 'text/plain',
            ): StoredDocumentArtifact {
                return new StoredDocumentArtifact(
                    sprintf('org-10/artifacts/%s-%d', $type->value, $index),
                    max(1, strlen($content)),
                    'sha256:'.hash('sha256', $content),
                    sprintf('artifact-%s-%d', $type->value, $index),
                    $contentType,
                );
            }
        };
        $pdf = new class extends PdfTextLayerExtractor
        {
            public function __construct() {}

            public function extractFile(string $path, ?string $filename = null): ?OcrRecognitionResult
            {
                return new OcrRecognitionResult('pdf', 'v1', [new OcrPageResult(1, 'plan')]);
            }
        };
        $geometry = new PdfGeometryExtractor(new class extends PdfGeometryWorker
        {
            public function extractFile(string $sourcePath, ?string $filename = null, ?callable $previewPublisher = null): array
            {
                $preview = tempnam(sys_get_temp_dir(), 'pdf-preview-');
                file_put_contents($preview, 'png');

                try {
                    return ['pages' => [[
                        'page_number' => 1,
                        'width' => 100,
                        'height' => 100,
                        'preview' => $previewPublisher(1, $preview, ['width' => 100, 'height' => 100]),
                    ]]];
                } finally {
                    unlink($preview);
                }
            }
        });
        $spreadsheet = new class extends SpreadsheetDocumentExtractor
        {
            public function extractFile(EstimateGenerationDocument $document, string $path): OcrRecognitionResult
            {
                return new OcrRecognitionResult('spreadsheet', 'v1', [
                    new OcrPageResult(1, 'sheet one'),
                    new OcrPageResult(2, 'sheet two'),
                ]);
            }
        };

        return new ArtifactDocumentUnitDetector(
            new PdfDocumentAdapter($storage, $pdf, $geometry),
            new ImageDocumentAdapter,
            new CadDocumentAdapter,
            new SpreadsheetDocumentAdapter($storage, $spreadsheet),
        );
    }
}
