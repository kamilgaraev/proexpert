<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CadRepresentationPublisher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProductionDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRouter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetRoleClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\TargetedSheetEvidenceResolver;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetEvidence;
use App\Models\Organization;
use App\Services\Storage\FileService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class ProductionDocumentUnitProcessorTest extends DatabaseLessTestCase
{
    #[Test]
    public function legacy_pending_spreadsheet_unit_uses_native_payload_without_ocr_retry(): void
    {
        $nativePayload = json_encode([
            'schema_version' => 1,
            'source_kind' => 'spreadsheet',
            'text' => 'Смета',
            'native_structure' => ['status' => 'available', 'sheet' => 'Смета', 'headings' => [], 'cells' => []],
        ], JSON_THROW_ON_ERROR);
        $ocr = new class implements OcrClientInterface
        {
            public int $calls = 0;

            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                $this->calls++;
                throw new \LogicException('OCR must not be called for a legacy spreadsheet artifact.');
            }
        };
        $processor = new OcrDocumentUnitProcessor(
            new class($nativePayload) implements DocumentUnitContentReader
            {
                public function __construct(private string $content) {}

                public function read(DocumentUnitExecutionContext $context): string
                {
                    return $this->content;
                }
            },
            $ocr,
        );
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $context = new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::SpreadsheetSheet, 1, $sourceVersion,
            [
                'source_kind' => 'spreadsheet',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'spreadsheet_cells',
                'artifact_path' => 'org-2/artifacts/spreadsheet-sheet-1',
                'artifact_bytes' => strlen($nativePayload),
                'artifact_sha256' => 'sha256:'.hash('sha256', $nativePayload),
                'artifact_source_version' => 'sha256:'.hash('sha256', $nativePayload),
                'content_type' => 'application/vnd.most.spreadsheet-sheet+json',
                'sheet' => 1,
            ],
            'org-2/source.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'source.xlsx',
            'retry-claim', 2, 1, 'processing_documents', 6,
        );

        $output = $processor->process($context);

        self::assertSame('Смета', $output->text);
        self::assertSame(1.0, $output->confidence);
        self::assertSame('available', $output->normalizedPayload['native_structure']['status']);
        self::assertSame(0, $ocr->calls);
    }

    #[Test]
    public function legacy_spreadsheet_mime_on_pdf_unit_uses_ocr_instead_of_native_transform(): void
    {
        $nativePayload = json_encode([
            'schema_version' => 1,
            'source_kind' => 'spreadsheet',
            'text' => 'Таблица',
            'native_structure' => ['status' => 'available', 'sheet' => 'Смета', 'headings' => [], 'cells' => []],
        ], JSON_THROW_ON_ERROR);
        $ocr = new class implements OcrClientInterface
        {
            public int $calls = 0;

            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                $this->calls++;

                return new OcrRecognitionResult('test', 'test-model', [new OcrPageResult(1, 'Текст PDF')]);
            }
        };
        $processor = new OcrDocumentUnitProcessor(
            new class($nativePayload) implements DocumentUnitContentReader
            {
                public function __construct(private string $content) {}

                public function read(DocumentUnitExecutionContext $context): string
                {
                    return $this->content;
                }
            },
            $ocr,
        );
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $context = new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::PdfPage, 1, $sourceVersion,
            [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_points',
                'artifact_path' => 'org-2/artifacts/pdf-page-1',
                'artifact_bytes' => strlen($nativePayload),
                'artifact_sha256' => 'sha256:'.hash('sha256', $nativePayload),
                'artifact_source_version' => 'sha256:'.hash('sha256', $nativePayload),
                'content_type' => 'application/vnd.most.spreadsheet-sheet+json',
                'page' => 1,
            ],
            'org-2/source.pdf', 'application/pdf', 'source.pdf',
            'retry-claim', 2, 1, 'processing_documents', 6,
        );

        $output = $processor->process($context);

        self::assertSame('Текст PDF', $output->text);
        self::assertSame(1, $ocr->calls);
    }

    #[Test]
    public function typed_cad_failure_preserves_safe_reason_for_document_status(): void
    {
        $original = new GeometryExtractionException('cad_geometry_empty');
        $processor = $this->cadFailureProcessor($original);

        try {
            $processor->process($this->cadContext());
            self::fail('Typed CAD failure must be wrapped.');
        } catch (DocumentUnitProcessingException $exception) {
            self::assertSame('cad_geometry_empty', $exception->safeCode);
            self::assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function unexpected_geometry_failure_keeps_original_exception_for_diagnostics(): void
    {
        $original = new \LogicException('geometry runtime failed');
        $processor = $this->cadFailureProcessor($original);
        $context = $this->cadContext();

        try {
            $processor->process($context);
            self::fail('Geometry failure must be wrapped.');
        } catch (DocumentUnitProcessingException $exception) {
            self::assertSame('document_geometry_processing_failed', $exception->safeCode);
            self::assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function production_conflict_recheck_sends_the_primary_and_real_peer_sheet_evidence(): void
    {
        $source = $this->png(12, 8);
        $peerImage = $this->png(5, 7);
        $objects = ['org-2/source.png' => ['body' => $source, 'content_type' => 'image/png']];
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $object = $objects[$path];

            return [
                'body' => $object['body'], 'content_type' => $object['content_type'],
                'size' => strlen($object['body']), 'sha256' => hash('sha256', $object['body']),
            ];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = ['body' => $body, 'content_type' => $contentType];

            return ['size' => strlen($body), 'sha256' => hash('sha256', $body)];
        });
        $vision = new class($this->sheetAnalysis('elevation'), $this->sheetAnalysis('floor_plan')) implements VisionProvider
        {
            /** @var list<VisionDocumentInput> */
            public array $inputs = [];

            public function __construct(
                private VisionAnalysisData $primary,
                private VisionAnalysisData $targeted,
            ) {}

            public function analyze(VisionDocumentInput $input): VisionAnalysisData
            {
                $this->inputs[] = $input;

                return count($this->inputs) === 1 ? $this->primary : $this->targeted;
            }
        };
        $peer = new TargetedSheetEvidence(
            2, 3, 4, 6, 18, 2, 20,
            'sha256:'.str_repeat('b', 64),
            'sha256:'.hash('sha256', $peerImage),
            'image/png',
            $peerImage,
        );
        $resolver = new class($peer) implements TargetedSheetEvidenceResolver
        {
            public function __construct(private TargetedSheetEvidence $peer) {}

            public function resolvePeer(DocumentUnitExecutionContext $context, string $role): ?TargetedSheetEvidence
            {
                return $this->peer;
            }
        };
        $reader = new BoundedVersionedS3ObjectReader($files);
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                $this->createMock(DocumentUnitContentReader::class),
                $this->createMock(OcrClientInterface::class),
            ),
            $vision,
            $this->createMock(CadGeometryProvider::class),
            new RasterPreprocessor($files, $reader),
            $reader,
            sheetAnalysisRouter: new SheetAnalysisRouter(new SheetRoleClassifier),
            targetedEvidenceResolver: $resolver,
        );
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $output = $processor->process(new DocumentUnitExecutionContext(
            5, 2, 3, 4, 6, DocumentUnitType::RasterImage, 1, $sourceVersion,
            [
                'source_kind' => 'image', 'source_version' => $sourceVersion, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'org-2/source.png', 'artifact_bytes' => strlen($source),
                'artifact_sha256' => 'sha256:'.hash('sha256', $source),
                'artifact_source_version' => 'sha256:'.hash('sha256', $source),
                'content_type' => 'image/png',
            ],
            'org-2/source.png', 'image/png', 'source.png', 'claim', 1, 1, 'processing_documents', 17,
            static fn (): bool => true,
        ));

        self::assertCount(2, $vision->inputs);
        self::assertSame(['document:6/sheet:17', 'document:6/sheet:18'], $vision->inputs[1]->recheckScope?->sourceSet);
        self::assertSame($peerImage, $vision->inputs[1]->supplementalEvidence[0]->imageContent);
        self::assertSame('succeeded', $output->normalizedPayload['sheet_analysis_routing']['outcome']);
    }

    private function cadFailureProcessor(\Throwable $error): ProductionDocumentUnitProcessor
    {
        $files = $this->createMock(FileService::class);

        return new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                new class implements DocumentUnitContentReader
                {
                    public function read(DocumentUnitExecutionContext $context): string
                    {
                        throw new \LogicException('OCR reader must not be called for CAD.');
                    }
                },
                new class implements OcrClientInterface
                {
                    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
                    {
                        throw new \LogicException('OCR must not be called for CAD.');
                    }
                },
            ),
            $this->createMock(VisionProvider::class),
            new class($error) implements CadGeometryProvider
            {
                public function __construct(private \Throwable $error) {}

                public function extract(DocumentUnitProvenance $source, Organization $organization): VectorGeometryData
                {
                    throw $this->error;
                }
            },
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
        );
    }

    private function cadContext(): DocumentUnitExecutionContext
    {
        return new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::CadDrawing, 1,
            'sha256:'.str_repeat('a', 64), $this->cadLocator(), 'org-2/source.dxf', 'application/dxf', 'source.dxf',
            'claim', 1, 1, 'processing_documents', 6,
        );
    }

    #[Test]
    public function container_binding_dispatches_real_cad_contract_without_legacy_ocr_fallback(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        self::assertStringContainsString('DocumentUnitProcessor::class, ProductionDocumentUnitProcessor::class', $provider);
        $recording = json_decode(file_get_contents($root.'/tests/Fixtures/EstimateGeneration/benchmarks/recordings/vector-pdf-001-geometry.json'), true, 512, JSON_THROW_ON_ERROR);
        $geometry = VectorGeometryData::fromArray($recording['payload']);
        $files = $this->createMock(FileService::class);
        $cad = new class($geometry) implements CadGeometryProvider
        {
            public ?DocumentUnitProvenance $source = null;

            public function __construct(private VectorGeometryData $geometry) {}

            public function extract(DocumentUnitProvenance $source, Organization $organization): VectorGeometryData
            {
                $this->source = $source;

                return $this->geometry;
            }
        };
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                new class implements DocumentUnitContentReader
                {
                    public function read(DocumentUnitExecutionContext $context): string
                    {
                        throw new \LogicException('OCR reader must not be called for CAD.');
                    }
                },
                new class implements OcrClientInterface
                {
                    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
                    {
                        throw new \LogicException('OCR must not be called for CAD.');
                    }
                },
            ),
            new class implements VisionProvider
            {
                public function analyze(VisionDocumentInput $input): VisionAnalysisData
                {
                    throw new \LogicException('Vision must not be called for CAD.');
                }
            },
            $cad,
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
        );
        $context = new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::CadDrawing, 1,
            'sha256:'.str_repeat('a', 64), $this->cadLocator(), 'org-2/source.dxf', 'application/dxf', 'source.dxf',
            'claim', 1, 1, 'processing', 6,
        );

        $output = $processor->process($context);

        self::assertSame('cad', $output->normalizedPayload['source_kind']);
        self::assertSame($geometry->runtimeVersion, $output->normalizedPayload['provenance']['runtime_version']);
        self::assertNotEmpty($output->normalizedPayload['vector_geometry']['entities']);
        self::assertSame('org-2/source.dxf', $cad->source?->artifactPath);
        self::assertSame(1, $cad->source?->artifactBytes);
        self::assertSame('sha256:'.str_repeat('a', 64), $cad->source?->artifactSha256);
    }

    #[Test]
    public function cad_processing_publishes_one_canonical_native_and_visual_s3_representation(): void
    {
        $root = dirname(__DIR__, 3);
        $recording = json_decode((string) file_get_contents(
            $root.'/tests/Fixtures/EstimateGeneration/benchmarks/recordings/vector-pdf-001-geometry.json',
        ), true, 512, JSON_THROW_ON_ERROR);
        $geometry = VectorGeometryData::fromArray($recording['payload']);
        $files = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods(['putImmutable'])->getMock();
        $files->expects(self::exactly(2))->method('putImmutable')
            ->willReturnCallback(static function (string $path, string $body, string $contentType): array {
                self::assertStringStartsWith('org-2/', $path);
                self::assertContains($contentType, ['application/json', 'image/svg+xml']);

                return ['size' => strlen($body), 'sha256' => hash('sha256', $body)];
            });
        $cad = new class($geometry) implements CadGeometryProvider
        {
            public function __construct(private VectorGeometryData $geometry) {}

            public function extract(DocumentUnitProvenance $source, Organization $organization): VectorGeometryData
            {
                return $this->geometry;
            }
        };
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                new class implements DocumentUnitContentReader
                {
                    public function read(DocumentUnitExecutionContext $context): string
                    {
                        throw new \LogicException;
                    }
                },
                new class implements OcrClientInterface
                {
                    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
                    {
                        throw new \LogicException;
                    }
                },
            ),
            $this->createMock(VisionProvider::class),
            $cad,
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
            cadRepresentationPublisher: new CadRepresentationPublisher($files),
        );

        $output = $processor->process($this->cadContext());
        $representation = $output->normalizedPayload['document_representation'];

        self::assertSame('cad', $representation['format']);
        self::assertSame('available', $representation['capabilities']['sheet_render']);
        self::assertNotEmpty($representation['native_structure']['native_reference_registry']);
        self::assertStringStartsWith('org-2/', $representation['visual_artifact_path']);
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function sheetAnalysis(string $sheetType): VisionAnalysisData
    {
        return VisionAnalysisData::fromProviderArray([
            'schema_version' => 3,
            'sheet_type' => $sheetType,
            'evidence' => [['key' => 'page-1', 'locator' => [
                'page_id' => 17, 'page_number' => 1, 'processing_unit_id' => 5,
                'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_source_v1',
            ]]],
            'elements' => [[
                'key' => 'room-1', 'type' => 'room', 'label' => null,
                'polygon' => [[0.1, 0.1], [0.9, 0.1], [0.9, 0.9]],
                'confidence' => 0.9, 'evidence_ref' => 'page-1',
            ]],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => ['roof_type' => ['value' => 'unknown', 'confidence' => 0.0, 'evidence_ref' => 'page-1']],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v2', 'role' => 'plan',
                'facts' => [[
                    'entityKey' => 'room-1', 'factType' => 'room',
                    'value' => ['type' => 'unknown', 'data' => null], 'unit' => null,
                    'evidenceRef' => 'page-1', 'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.9, 0.9]],
                    'confidence' => 0.9, 'contractVersion' => 'sheet-analysis:v2',
                ]],
            ],
        ], 'test', 'model', 'model', 'v1', 'unavailable', null, null, 10);
    }

    /** @return array<string, scalar> */
    private function cadLocator(): array
    {
        $sourceVersion = 'sha256:'.str_repeat('a', 64);

        return [
            'source_kind' => 'cad',
            'source_version' => $sourceVersion,
            'coordinate_space' => 'cad_model',
            'artifact_path' => 'org-2/source.dxf',
            'artifact_bytes' => 1,
            'artifact_sha256' => $sourceVersion,
            'artifact_source_version' => $sourceVersion,
            'content_type' => 'application/dxf',
        ];
    }
}
