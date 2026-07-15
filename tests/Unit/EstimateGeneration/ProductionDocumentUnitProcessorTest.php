<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
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
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\Models\Organization;
use App\Services\Storage\FileService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionDocumentUnitProcessorTest extends TestCase
{
    #[Test]
    public function unexpected_geometry_failure_keeps_original_exception_for_diagnostics(): void
    {
        $original = new \LogicException('geometry runtime failed');
        $files = $this->createMock(FileService::class);
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
            $this->createMock(VisionProvider::class),
            new class($original) implements CadGeometryProvider
            {
                public function __construct(private \Throwable $error) {}

                public function extract(string $storageKey, Organization $organization): VectorGeometryData
                {
                    throw $this->error;
                }
            },
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
        );
        $context = new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::CadDrawing, 1,
            'sha256:'.str_repeat('a', 64), [], 'org-2/source.dxf', 'application/dxf', 'source.dxf',
            'claim', 1, 1, 'processing_documents', 6,
        );

        try {
            $processor->process($context);
            self::fail('Geometry failure must be wrapped.');
        } catch (DocumentUnitProcessingException $exception) {
            self::assertSame('document_geometry_processing_failed', $exception->safeCode);
            self::assertSame($original, $exception->getPrevious());
        }
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
            new class($geometry) implements CadGeometryProvider
            {
                public function __construct(private VectorGeometryData $geometry) {}

                public function extract(string $storageKey, Organization $organization): VectorGeometryData
                {
                    return $this->geometry;
                }
            },
            new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)),
            new BoundedVersionedS3ObjectReader($files),
        );
        $context = new DocumentUnitExecutionContext(
            1, 2, 3, 4, 5, DocumentUnitType::CadDrawing, 1,
            'sha256:'.str_repeat('a', 64), [], 'org-2/source.dxf', 'application/dxf', 'source.dxf',
            'claim', 1, 1, 'processing', 6,
        );

        $output = $processor->process($context);

        self::assertSame('cad', $output->normalizedPayload['source_kind']);
        self::assertSame($geometry->runtimeVersion, $output->normalizedPayload['provenance']['runtime_version']);
        self::assertNotEmpty($output->normalizedPayload['vector_geometry']['entities']);
    }
}
