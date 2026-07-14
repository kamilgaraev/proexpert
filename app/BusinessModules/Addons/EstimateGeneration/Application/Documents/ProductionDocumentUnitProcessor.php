<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\Models\Organization;
use Throwable;

final readonly class ProductionDocumentUnitProcessor implements DocumentUnitProcessor
{
    public function __construct(
        private OcrDocumentUnitProcessor $ocr,
        private VisionProvider $vision,
        private CadGeometryProvider $cad,
        private RasterPreprocessor $raster,
        private BoundedVersionedS3ObjectReader $reader,
    ) {}

    public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        try {
            if ($context->type === DocumentUnitType::PdfPage
                && ($context->locator['content_type'] ?? null) === 'image/png') {
                return $this->processRaster($context);
            }

            return match ($context->type) {
                DocumentUnitType::CadDrawing => $this->processCad($context),
                DocumentUnitType::RasterImage, DocumentUnitType::Sketch => $this->processRaster($context),
                default => $this->ocr->process($context),
            };
        } catch (DocumentUnitProcessingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DocumentUnitProcessingException('document_geometry_processing_failed');
        }
    }

    private function processCad(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        $organization = new Organization;
        $organization->id = $context->organizationId;
        $geometry = $this->cad->extract($context->storagePath, $organization);
        $payload = $geometry->toArray();
        $text = implode("\n", array_values(array_filter(array_map(
            static fn (mixed $item): string => is_array($item) ? trim((string) ($item['text'] ?? '')) : '',
            $payload['texts'],
        ))));

        return new DocumentUnitOutput(
            version: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            text: $text,
            confidence: $geometry->unitStatus === 'confirmed' ? 1.0 : 0.7,
            normalizedPayload: [
                'schema_version' => 1,
                'source_kind' => 'cad',
                'vector_geometry' => $payload,
                'provenance' => [
                    'provider' => 'cad_geometry',
                    'runtime_version' => $geometry->runtimeVersion,
                    'source_version' => $context->sourceVersion,
                    'source_fingerprint' => $geometry->sourceFingerprint,
                ],
            ],
            unitType: $context->type,
            unitIndex: $context->index,
            sourceVersion: $context->sourceVersion,
        );
    }

    private function processRaster(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        if ($context->pageId === null) {
            throw new DocumentUnitProcessingException('vision_page_identity_required');
        }
        $storageKey = is_string($context->locator['artifact_path'] ?? null)
            ? $context->locator['artifact_path']
            : $context->storagePath;
        $artifactVersion = is_string($context->locator['artifact_source_version'] ?? null)
            ? $context->locator['artifact_source_version']
            : $context->sourceVersion;
        $preprocessed = $this->raster->preprocess(new RasterPreprocessInput(
            organizationId: $context->organizationId,
            sessionId: $context->sessionId,
            documentId: $context->documentId,
            pageNumber: $context->index,
            sourceVersion: $artifactVersion,
            storageKey: $storageKey,
            contentType: is_string($context->locator['content_type'] ?? null)
                ? $context->locator['content_type']
                : $context->mimeType,
            perspectiveRequired: $context->type === DocumentUnitType::Sketch,
        ));
        $image = $this->reader->read(
            $context->organizationId,
            $preprocessed->derivativeStorageKey,
            max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000)),
            expectedSha256: $preprocessed->derivativeHash,
        )->body;
        $correlationId = AiOperationContext::deterministicId(implode('|', [
            'vision-unit', $context->sessionId, $context->documentId, $context->unitId,
            $context->sourceVersion, $context->claimToken, $context->unitAttemptCount,
        ]));
        $input = new VisionDocumentInput(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            documentId: $context->documentId,
            pageId: $context->pageId,
            pageNumber: $context->index,
            processingUnitId: $context->unitId,
            sourceVersion: $context->sourceVersion,
            derivativeHash: $preprocessed->derivativeHash,
            contentType: 'image/png',
            imageContent: $image,
            imageDetail: 'high',
            operationContext: new AiOperationContext(
                correlationId: $correlationId,
                attemptId: $correlationId,
                organizationId: $context->organizationId,
                projectId: $context->projectId,
                sessionId: $context->sessionId,
                stage: 'understand_documents',
                operation: 'vision',
                attemptOrdinal: 1,
                documentId: $context->documentId,
                pageId: $context->pageId,
                unitId: $context->unitId,
            ),
            sourceTransform: $preprocessed->transform,
        );
        $analysis = $this->vision->analyze($input)->mapPolygonsToSource($preprocessed->transform);
        $payload = $analysis->toArray();
        $pdfGeometry = null;
        $geometryPath = $context->locator['geometry_artifact_path'] ?? null;
        if (is_string($geometryPath)) {
            $geometryBytes = $context->locator['geometry_artifact_bytes'] ?? null;
            $geometrySha256 = $context->locator['geometry_artifact_sha256'] ?? null;
            $geometryVersionId = $context->locator['geometry_artifact_version_id'] ?? null;
            if (! is_int($geometryBytes) || ! is_string($geometrySha256) || ! is_string($geometryVersionId)) {
                throw new DocumentUnitProcessingException('pdf_page_geometry_locator_invalid');
            }
            $geometryContent = $this->reader->read(
                $context->organizationId,
                $geometryPath,
                max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
                $geometryBytes,
                $geometrySha256,
                $geometryVersionId,
            )->body;
            $decoded = json_decode($geometryContent, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! is_array($decoded['geometry'] ?? null)) {
                throw new DocumentUnitProcessingException('pdf_page_geometry_contract_invalid');
            }
            $pdfGeometry = $decoded;
        }

        return new DocumentUnitOutput(
            version: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            text: implode("\n", array_values(array_filter(array_map(
                static fn (array $element): string => trim((string) ($element['label'] ?? '')),
                $payload['elements'],
            )))),
            confidence: $analysis->warnings === [] ? 1.0 : 0.7,
            normalizedPayload: [
                'schema_version' => 1,
                'source_kind' => $context->type->value,
                'vision_analysis' => $payload,
                'pdf_geometry' => $pdfGeometry,
                'preprocessing' => [
                    'version' => $preprocessed->derivativeVersion,
                    'derivative_hash' => $preprocessed->derivativeHash,
                    'perspective_status' => $preprocessed->perspectiveStatus,
                    'warnings' => $preprocessed->warnings,
                ],
                'provenance' => [
                    'provider' => $analysis->provider,
                    'model' => $analysis->reportedModel,
                    'model_version' => $analysis->modelVersion,
                    'source_version' => $context->sourceVersion,
                ],
            ],
            width: $preprocessed->sourceWidth,
            height: $preprocessed->sourceHeight,
            unitType: $context->type,
            unitIndex: $context->index,
            sourceVersion: $context->sourceVersion,
        );
    }
}
