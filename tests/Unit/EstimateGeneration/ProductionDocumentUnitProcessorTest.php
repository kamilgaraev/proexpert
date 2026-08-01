<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProductionDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
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
use App\Models\Organization;
use App\Services\Storage\FileService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionDocumentUnitProcessorTest extends TestCase
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
                'artifact_version_id' => 'legacy-sheet-v1',
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
        self::assertSame('source-v1', $cad->source?->artifactVersionId);
        self::assertSame('sha256:'.str_repeat('a', 64), $cad->source?->artifactSha256);
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
            'artifact_version_id' => 'source-v1',
            'artifact_source_version' => $sourceVersion,
            'content_type' => 'application/dxf',
        ];
    }
}
