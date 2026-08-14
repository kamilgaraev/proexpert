<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\DocumentArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\DocumentObserverRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CadDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CadRepresentationPublisher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSemanticUnderstandingSummarizer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ImageDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PdfDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProductionDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\Models\Organization;
use App\Services\Storage\FileService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class ProductionDocumentUnitProcessorTest extends DatabaseLessTestCase
{
    #[Test]
    public function production_representative_pdf_renders_reach_deterministic_vision_boundary(): void
    {
        $renderDirectory = getenv('MOST_PRODUCTION_PDF_RENDER_DIR');
        if (! is_string($renderDirectory) || ! is_dir($renderDirectory)) {
            self::markTestSkipped('Requires explicit production-representative PDF render fixture directory.');
        }
        $paths = glob(rtrim($renderDirectory, '/\\').'/*.png') ?: [];
        natsort($paths);
        $paths = array_values($paths);
        self::assertCount(22, $paths);
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $objects = [];
        foreach ($paths as $offset => $path) {
            $body = (string) file_get_contents($path);
            $objects['org-38/incident/page-'.($offset + 1).'.png'] = ['body' => $body, 'content_type' => 'image/png'];
        }
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $object = $objects[$path];

            return ['path' => $path, 'body' => $object['body'], 'content_type' => $object['content_type'],
                'size' => strlen($object['body']), 'sha256' => hash('sha256', $object['body']), 'etag' => hash('md5', $object['body'])];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = ['body' => $body, 'content_type' => $contentType];

            return ['path' => $path, 'body' => $body, 'content_type' => $contentType, 'size' => strlen($body),
                'sha256' => hash('sha256', $body), 'etag' => hash('md5', $body), 'created' => true];
        });
        $vision = new class($this->sheetAnalysis('floor_plan')) implements VisionProvider
        {
            /** @var list<VisionDocumentInput> */
            public array $inputs = [];

            public function __construct(private VisionAnalysisData $analysis) {}

            public function analyze(VisionDocumentInput $input): VisionAnalysisData
            {
                $this->inputs[] = $input;

                return $this->analysis;
            }
        };
        $reader = new BoundedVersionedS3ObjectReader($files);
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor($this->createMock(DocumentUnitContentReader::class), $this->createMock(OcrClientInterface::class)),
            $this->createMock(CadGeometryProvider::class),
            new RasterPreprocessor($files, $reader),
            $reader,
            $this->observerRunner($vision),
            $this->arbitrator(),
            $this->geometryRunner(),
        );
        foreach ($paths as $offset => $path) {
            $index = $offset + 1;
            $body = (string) file_get_contents($path);
            $artifactVersion = 'sha256:'.hash('sha256', $body);
            $processor->process(new DocumentUnitExecutionContext(
                2000 + $index, 38, 52, 66, 168, DocumentUnitType::PdfPage, $index, $sourceVersion,
                ['source_kind' => 'pdf', 'source_version' => $sourceVersion, 'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => 'org-38/incident/page-'.$index.'.png', 'artifact_source_version' => $artifactVersion,
                    'artifact_bytes' => strlen($body), 'artifact_sha256' => $artifactVersion, 'content_type' => 'image/png'],
                'org-38/incident/source.pdf', 'application/pdf', 'incident.pdf', 'claim-'.$index, 1, 1,
                'processing_documents', 17, static fn (): bool => true,
            ));
        }

        self::assertCount(22, $vision->inputs);
        foreach ($vision->inputs as $input) {
            self::assertSame('image/png', $input->contentType);
            self::assertSame('high', $input->imageDetail);
            self::assertSame([2382, 1684], array_slice(getimagesizefromstring($input->imageContent), 0, 2));
        }
    }

    #[Test]
    public function png_runs_detector_representation_and_production_processor_as_one_path(): void
    {
        $source = $this->png(12, 8);
        $sourceVersion = 'sha256:'.hash('sha256', $source);
        $document = new EstimateGenerationDocument([
            'id' => 4,
            'organization_id' => 2,
            'project_id' => 3,
            'session_id' => 5,
            'filename' => 'source.png',
            'mime_type' => 'image/png',
            'storage_path' => 'org-2/source.png',
            'file_size_bytes' => strlen($source),
            'meta' => ['storage_sha256' => substr($sourceVersion, 7)],
        ]);
        $storage = $this->createMock(DocumentSourceManifestStorage::class);
        $detector = new ArtifactDocumentUnitDetector(
            new PdfDocumentAdapter(
                $storage,
                $this->createMock(PdfTextLayerExtractor::class),
                new PdfGeometryExtractor($this->createMock(PdfGeometryWorker::class)),
            ),
            new ImageDocumentAdapter,
            new CadDocumentAdapter,
            new SpreadsheetDocumentAdapter($storage, new SpreadsheetDocumentExtractor),
        );
        $unit = $detector->detect($document, $sourceVersion)[0];
        $objects = ['org-2/source.png' => ['body' => $source, 'content_type' => 'image/png']];
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $object = $objects[$path];

            return [
                'path' => $path,
                'body' => $object['body'],
                'content_type' => $object['content_type'],
                'size' => strlen($object['body']),
                'sha256' => hash('sha256', $object['body']),
                'etag' => hash('md5', $object['body']),
            ];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = ['body' => $body, 'content_type' => $contentType];

            return ['path' => $path, 'body' => $body, 'content_type' => $contentType, 'size' => strlen($body), 'sha256' => hash('sha256', $body), 'etag' => hash('md5', $body), 'created' => true];
        });
        $vision = new class($this->sheetAnalysis('floor_plan')) implements VisionProvider
        {
            public function __construct(private VisionAnalysisData $analysis) {}

            public function analyze(VisionDocumentInput $input): VisionAnalysisData
            {
                return $this->analysis;
            }
        };
        $reader = new BoundedVersionedS3ObjectReader($files);
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                $this->createMock(DocumentUnitContentReader::class),
                $this->createMock(OcrClientInterface::class),
            ),
            $this->createMock(CadGeometryProvider::class),
            new RasterPreprocessor($files, $reader),
            $reader,
            $this->observerRunner($vision),
            $this->arbitrator(),
            $this->geometryRunner(),
        );
        $locator = $unit->locator;
        $locator['document_representation']['capabilities'] = array_reverse(
            $locator['document_representation']['capabilities'],
            true,
        );
        $locator['document_representation']['resource_usage'] = array_reverse(
            $locator['document_representation']['resource_usage'],
            true,
        );
        $output = $processor->process(new DocumentUnitExecutionContext(
            1,
            2,
            3,
            5,
            4,
            $unit->type,
            $unit->index,
            $sourceVersion,
            $locator,
            'org-2/source.png',
            'image/png',
            'source.png',
            'claim',
            1,
            1,
            'processing_documents',
            17,
            static fn (): bool => true,
        ));

        self::assertSame('image', $output->normalizedPayload['document_representation']['format']);
        self::assertSame($unit->index, $output->normalizedPayload['page_number']);
        $semanticSummary = (new DocumentSemanticUnderstandingSummarizer)->summarize([$output->normalizedPayload]);
        self::assertSame(1, $semanticSummary['pages_checked']);
        self::assertTrue($semanticSummary['analysis_roles_complete']);
        self::assertSame([
            'observer_literal' => true,
            'observer_construction' => true,
            'observer_risk' => true,
            'arbiter' => true,
            'geometry_expert' => true,
        ], $output->normalizedPayload['role_completion']);
        self::assertSame(4, $output->normalizedPayload['analysis_routing']['physical_provider_call_count']);
        self::assertCount(4, $output->normalizedPayload['analysis_routing']['physical_provider_attempt_ids']);
        self::assertSame($sourceVersion, $output->normalizedPayload['document_representation']['source_version']);
        self::assertGreaterThan(0, $output->normalizedPayload['document_representation']['resource_usage']['duration_ms']);
        self::assertSame(
            ['adapter_representation', 'processor'],
            $output->normalizedPayload['document_representation']['native_structure']['resource_measurement']['phases'],
        );
        self::assertSame('image_pixels', $output->normalizedPayload['document_representation']['coordinate_space']);
    }

    #[Test]
    public function invalid_auxiliary_pdf_geometry_degrades_to_full_page_vision(): void
    {
        $source = $this->png(12, 8);
        $geometry = json_encode(['schema_version' => 0], JSON_THROW_ON_ERROR);
        $sourceVersion = 'sha256:'.hash('sha256', $source);
        $objects = [
            'org-2/page.png' => ['body' => $source, 'content_type' => 'image/png'],
            'org-2/page.json' => ['body' => $geometry, 'content_type' => 'application/json'],
        ];
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $object = $objects[$path];

            return [
                'path' => $path,
                'body' => $object['body'],
                'content_type' => $object['content_type'],
                'size' => strlen($object['body']),
                'sha256' => hash('sha256', $object['body']),
                'etag' => hash('md5', $object['body']),
            ];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = ['body' => $body, 'content_type' => $contentType];

            return ['path' => $path, 'body' => $body, 'content_type' => $contentType, 'size' => strlen($body), 'sha256' => hash('sha256', $body), 'etag' => hash('md5', $body), 'created' => true];
        });
        $vision = new class($this->sheetAnalysis('floor_plan')) implements VisionProvider
        {
            public int $calls = 0;

            /** @var list<VisionDocumentInput> */
            public array $inputs = [];

            public function __construct(private VisionAnalysisData $analysis) {}

            public function analyze(VisionDocumentInput $input): VisionAnalysisData
            {
                $this->calls++;
                $this->inputs[] = $input;

                return $this->analysis;
            }
        };
        $reader = new BoundedVersionedS3ObjectReader($files);
        $processor = new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                $this->createMock(DocumentUnitContentReader::class),
                $this->createMock(OcrClientInterface::class),
            ),
            $this->createMock(CadGeometryProvider::class),
            new RasterPreprocessor($files, $reader),
            $reader,
            $this->observerRunner($vision),
            $this->arbitrator(),
            $this->geometryRunner(),
        );
        $context = new DocumentUnitExecutionContext(
            1,
            2,
            3,
            5,
            4,
            DocumentUnitType::PdfPage,
            1,
            $sourceVersion,
            [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'org-2/page.png',
                'artifact_source_version' => $sourceVersion,
                'artifact_bytes' => strlen($source),
                'artifact_sha256' => $sourceVersion,
                'content_type' => 'image/png',
                'geometry_artifact_path' => 'org-2/page.json',
                'geometry_artifact_bytes' => strlen($geometry),
                'geometry_artifact_sha256' => 'sha256:'.hash('sha256', $geometry),
            ],
            'org-2/source.pdf',
            'application/pdf',
            'source.pdf',
            'claim',
            1,
            1,
            'processing_documents',
            17,
            static fn (): bool => true,
        );

        $output = $processor->process($context);

        self::assertSame(1, $vision->calls);
        self::assertSame('image/png', $vision->inputs[0]->contentType);
        self::assertSame([12, 8], array_slice(getimagesizefromstring($vision->inputs[0]->imageContent), 0, 2));
        self::assertSame('unavailable:pdf_geometry_contract_invalid', $vision->inputs[0]->auxiliaryMetadata['geometry_status']);
        self::assertSame('available', $vision->inputs[0]->auxiliaryMetadata['capabilities']['page_render']);
        self::assertNull($output->normalizedPayload['pdf_geometry']);
        self::assertSame(
            'unavailable:pdf_geometry_contract_invalid',
            $output->normalizedPayload['auxiliary_sources']['pdf_geometry']['status'],
        );
        self::assertSame(
            'unavailable:pdf_vectors_missing',
            $output->normalizedPayload['document_representation']['capabilities']['vectors'],
        );
        self::assertSame(1, $output->normalizedPayload['document_representation']['schema_version']);
    }

    #[Test]
    public function generated_png_dimension_boundary_rejects_before_pixel_decode(): void
    {
        $small = $this->png(1, 1);
        $ihdr = pack('NN', 50_000, 50_000).substr($small, 24, 5);
        $chunk = static fn (string $type, string $body): string => pack('N', strlen($body))
            .$type.$body.pack('N', crc32($type.$body));
        $bytes = substr($small, 0, 8).$chunk('IHDR', $ihdr).substr($small, 33);
        $version = 'sha256:'.hash('sha256', $bytes);
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturn([
            'path' => 'org-2/source.png',
            'body' => $bytes,
            'content_type' => 'image/png',
            'size' => strlen($bytes),
            'sha256' => substr($version, 7),
            'etag' => hash('md5', $bytes),
        ]);
        $preprocessor = new RasterPreprocessor($files, new BoundedVersionedS3ObjectReader($files));

        $this->expectException(RasterPreprocessingException::class);
        $this->expectExceptionMessage('unsafe_image_dimensions');
        $preprocessor->preprocess(new RasterPreprocessInput(
            2,
            3,
            4,
            1,
            $version,
            'org-2/source.png',
            'image/png',
            strlen($bytes),
            $version,
            maxPixels: 20_000_000,
        ));
    }

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
        } catch (TypedFailureException $exception) {
            self::assertSame(FailureCategory::Terminal, $exception->category);
            self::assertSame('cad_geometry_empty', $exception->safeCode);
            self::assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function retryable_geometry_failure_preserves_recoverable_category(): void
    {
        $original = new GeometryExtractionException('cad_runtime_unavailable', true);

        try {
            $this->cadFailureProcessor($original)->process($this->cadContext());
            self::fail('Retryable geometry failure must escape as typed recoverable failure.');
        } catch (TypedFailureException $exception) {
            self::assertSame(FailureCategory::Recoverable, $exception->category);
            self::assertSame('cad_runtime_unavailable', $exception->safeCode);
            self::assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function retryable_vision_failure_preserves_recoverable_category(): void
    {
        $fingerprint = 'sha256:'.str_repeat('c', 64);
        $original = new VisionProviderException(
            'vision_provider_unavailable',
            503,
            true,
            safeContext: ['diagnostic_fingerprint' => $fingerprint, 'provider_http_status' => 503],
        );

        try {
            $this->visionFailureProcessor($original)->process($this->rasterContext());
            self::fail('Retryable vision failure must escape as typed recoverable failure.');
        } catch (TypedFailureException $exception) {
            self::assertSame(FailureCategory::Recoverable, $exception->category);
            self::assertSame('vision_provider_unavailable', $exception->safeCode);
            self::assertSame($fingerprint, $exception->safeContext['diagnostic_fingerprint']);
            self::assertSame(503, $exception->safeContext['provider_http_status']);
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
        } catch (TypedFailureException $exception) {
            self::assertSame('document_unit_pre_wire_failed', $exception->safeCode);
            self::assertSame('document_unit_representation', $exception->safeContext['execution_boundary']);
            self::assertSame($original, $exception->getPrevious());
        }
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
            $this->observerRunner(),
            $this->arbitrator(),
            $this->geometryRunner(),
        );
    }

    private function visionFailureProcessor(VisionProviderException $error): ProductionDocumentUnitProcessor
    {
        $source = $this->png(12, 8);
        $objects = ['org-2/page.png' => ['body' => $source, 'content_type' => 'image/png']];
        $files = $this->createMock(FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $object = $objects[$path];

            return [
                'path' => $path,
                'body' => $object['body'],
                'content_type' => $object['content_type'],
                'size' => strlen($object['body']),
                'sha256' => hash('sha256', $object['body']),
                'etag' => hash('md5', $object['body']),
            ];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = ['body' => $body, 'content_type' => $contentType];

            return ['path' => $path, 'body' => $body, 'content_type' => $contentType, 'size' => strlen($body), 'sha256' => hash('sha256', $body), 'etag' => hash('md5', $body), 'created' => true];
        });
        $reader = new BoundedVersionedS3ObjectReader($files);

        return new ProductionDocumentUnitProcessor(
            new OcrDocumentUnitProcessor(
                $this->createMock(DocumentUnitContentReader::class),
                $this->createMock(OcrClientInterface::class),
            ),
            $this->createMock(CadGeometryProvider::class),
            new RasterPreprocessor($files, $reader),
            $reader,
            $this->observerRunner(error: $error),
            $this->arbitrator(),
            $this->geometryRunner(),
        );
    }

    private function rasterContext(): DocumentUnitExecutionContext
    {
        $source = $this->png(12, 8);
        $version = 'sha256:'.hash('sha256', $source);

        return new DocumentUnitExecutionContext(
            1, 2, 3, 5, 4, DocumentUnitType::PdfPage, 1, $version,
            [
                'source_kind' => 'pdf',
                'source_version' => $version,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'org-2/page.png',
                'artifact_source_version' => $version,
                'artifact_bytes' => strlen($source),
                'artifact_sha256' => $version,
                'content_type' => 'image/png',
            ],
            'org-2/source.pdf', 'application/pdf', 'source.pdf', 'claim', 1, 1, 'processing_documents', 17,
            static fn (): bool => true,
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
        $recording = json_decode(file_get_contents($root.'/tests/Fixtures/EstimateGeneration/document-processing/vector-pdf-geometry.json'), true, 512, JSON_THROW_ON_ERROR);
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
            $cad,
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
            $this->observerRunner(),
            $this->arbitrator(),
            $this->geometryRunner(),
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
            $root.'/tests/Fixtures/EstimateGeneration/document-processing/vector-pdf-geometry.json',
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
            $cad,
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
            $this->observerRunner(),
            $this->arbitrator(),
            $this->geometryRunner(),
            cadRepresentationPublisher: new CadRepresentationPublisher($files),
        );

        $output = $processor->process($this->cadContext());
        $representation = $output->normalizedPayload['document_representation'];

        self::assertSame('cad', $representation['format']);
        self::assertSame('available', $representation['capabilities']['sheet_render']);
        self::assertNotEmpty($representation['native_structure']['native_reference_registry']);
        self::assertStringStartsWith('org-2/', $representation['visual_artifact_path']);
    }

    private function observerRunner(?VisionProvider $provider = null, ?\Throwable $error = null): DocumentObserverRunner
    {
        return new class($provider, $error) implements DocumentObserverRunner
        {
            public function __construct(
                private readonly ?VisionProvider $provider,
                private readonly ?\Throwable $error,
            ) {}

            public function run(VisionDocumentInput $source, array $profiles): array
            {
                if ($this->error !== null) {
                    throw $this->error;
                }
                $analysis = $this->provider?->analyze($source);
                $observation = [
                    'sheet_type' => $analysis?->sheetType ?? 'unknown',
                    'elements' => $analysis?->toArray()['elements'] ?? [],
                    'visual_attributes' => $analysis?->visualAttributes ?? [],
                    'warnings' => $analysis?->warnings ?? ['scale_missing'],
                    'quarantined_items' => [],
                    'raw_facts' => [],
                    'analysis_routing' => [
                        'page_kind' => 'drawing',
                        'requested_depth' => 'dense_ambiguous',
                        'information_density' => 'high',
                        'readability' => 'medium',
                        'confidence' => 0.95,
                        'ambiguous' => false,
                        'material_risk' => true,
                        'reasons' => ['test_dense_page'],
                        'semantic_regions' => [],
                    ],
                ];
                $results = [];
                foreach ($profiles as $profile) {
                    if (! $profile instanceof ObserverProfile) {
                        throw new \InvalidArgumentException('Unexpected observer profile.');
                    }
                    $role = $profile->role()->value;
                    $results[$role] = new AiRoleRunResult([
                        'schema_version' => 1,
                        'role' => $role,
                        'source' => ['page_number' => $source->pageNumber],
                        'observation' => $observation,
                        'claims' => [],
                        'evidence' => [],
                    ], match ($role) {
                        'observer_literal' => '10000000-0000-4000-8000-000000000001',
                        'observer_construction' => '10000000-0000-4000-8000-000000000002',
                        'observer_risk' => '10000000-0000-4000-8000-000000000003',
                    });
                }

                return $results;
            }
        };
    }

    private function arbitrator(): DocumentArbitrator
    {
        return new class implements DocumentArbitrator
        {
            public function run(VisionDocumentInput $source, array $observerRuns): AiRoleRunResult
            {
                return new AiRoleRunResult([
                    'schema_version' => 1,
                    'role' => 'arbiter',
                    'source' => ['page_number' => $source->pageNumber],
                    'decisions' => [],
                    'questions' => [],
                ], '10000000-0000-4000-8000-000000000004');
            }
        };
    }

    private function geometryRunner(): GeometryExpertRunner
    {
        return new class implements GeometryExpertRunner
        {
            public function run(GeometryExpertInput $input): GeometryExpertResult
            {
                return new GeometryExpertResult([], [], [], []);
            }
        };
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
                'contractVersion' => 'sheet-analysis:v3', 'role' => 'plan',
                'facts' => [[
                    'entityKey' => 'room-1', 'factType' => 'room',
                    'value' => ['type' => 'unknown', 'data' => null], 'unit' => null,
                    'evidenceRef' => 'page-1', 'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.9, 0.9]],
                    'confidence' => 0.9, 'contractVersion' => 'sheet-analysis:v3',
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
