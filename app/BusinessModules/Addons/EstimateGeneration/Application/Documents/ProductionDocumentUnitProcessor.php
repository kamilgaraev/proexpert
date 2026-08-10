<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\DocumentSheetOperationScope;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationJournal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRouter;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Cad\CadStructureExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetRecheckScope;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
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
        private ?SheetAnalysisRouter $sheetAnalysisRouter = null,
        private ?SheetAnalysisOperationJournal $sheetAnalysisJournal = null,
        private CadStructureExtractor $cadStructure = new CadStructureExtractor,
    ) {}

    public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        try {
            $provenance = DocumentUnitProvenance::fromLocator($context->type, $context->sourceVersion, $context->locator);
            $output = match (true) {
                $context->type === DocumentUnitType::PdfPage && ($context->locator['content_type'] ?? null) === 'image/png' => $this->processRaster($context, $provenance),
                $context->type === DocumentUnitType::CadDrawing => $this->processCad($context, $provenance),
                $context->type === DocumentUnitType::RasterImage, $context->type === DocumentUnitType::Sketch => $this->processRaster($context, $provenance),
                default => $this->ocr->process($context),
            };

            return $this->withSourceProvenance($output, $provenance);
        } catch (DocumentUnitProcessingException $exception) {
            throw $exception;
        } catch (S3ObjectLocatorException $exception) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_artifact_integrity_failed', previous: $exception);
        } catch (S3ObjectTransportException $exception) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
        } catch (GeometryExtractionException $exception) {
            throw new DocumentUnitProcessingException($exception->reason, $exception);
        } catch (Throwable $exception) {
            throw new DocumentUnitProcessingException('document_geometry_processing_failed', $exception);
        }
    }

    private function processCad(DocumentUnitExecutionContext $context, DocumentUnitProvenance $provenance): DocumentUnitOutput
    {
        $organization = new Organization;
        $organization->id = $context->organizationId;
        $geometry = $this->cad->extract($provenance, $organization);
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
                'source_kind' => $provenance->sourceKind,
                'source' => $provenance->toArray(),
                'vector_geometry' => $payload,
                ...$this->cadStructure->extract($geometry),
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
            qualitySignals: [
                'geometry' => [
                    'confidence' => $geometry->unitStatus === 'confirmed' ? 1.0 : 0.7,
                    'hard_blockers' => $geometry->unitStatus === 'confirmed' ? [] : ['unit_unconfirmed'],
                ],
            ],
        );
    }

    private function processRaster(DocumentUnitExecutionContext $context, DocumentUnitProvenance $provenance): DocumentUnitOutput
    {
        if ($context->pageId === null) {
            throw new DocumentUnitProcessingException('vision_page_identity_required');
        }
        $storageKey = $provenance->artifactPath;
        $artifactVersion = $context->locator['artifact_source_version'] ?? null;
        $sourceBytes = $context->locator['artifact_bytes'] ?? null;
        $sourceSha256 = $context->locator['artifact_sha256'] ?? null;
        if (! is_string($artifactVersion) || ! is_int($sourceBytes) || ! is_string($sourceSha256)) {
            throw new DocumentUnitProcessingException('raster_source_locator_invalid');
        }
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
            sourceBytes: $sourceBytes,
            sourceSha256: $sourceSha256,
            perspectiveRequired: $context->type === DocumentUnitType::Sketch,
        ));
        $image = $this->reader->read(
            $context->organizationId,
            $preprocessed->derivativeStorageKey,
            max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000)),
            $preprocessed->derivativeBytes,
            $preprocessed->derivativeHash,
        )->body;
        $correlationId = SheetAnalysisOperationIdentity::primary(
            $context->sessionId, $context->documentId, $context->unitId, $context->sourceVersion, $preprocessed->derivativeHash,
        );
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
        $scope = new DocumentSheetOperationScope($context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->unitId, $context->sourceVersion, $context->claimToken);
        $primaryRouting = ['role' => 'unknown', 'needs_review' => false, 'outcome' => 'not_applicable'];
        $primaryRun = $this->sheetAnalysisJournal?->run($correlationId, 'primary', $scope, $primaryRouting,
            function () use ($context, $input, $preprocessed) {
                $context->renewLeaseOrFail();

                return $this->vision->analyze($input)->mapPolygonsToSource($preprocessed->transform);
            });
        if ($primaryRun !== null) {
            $analysis = $primaryRun->analysis;
        } else {
            $context->renewLeaseOrFail();
            $analysis = $this->vision->analyze($input)->mapPolygonsToSource($preprocessed->transform);
        }
        if ($analysis === null) {
            throw new DocumentUnitProcessingException('sheet_analysis_requires_review');
        }
        $pdfGeometry = null;
        $nativePdfText = null;
        $geometryPath = $context->locator['geometry_artifact_path'] ?? null;
        if ($context->type === DocumentUnitType::PdfPage && ! is_string($geometryPath)) {
            throw new DocumentUnitProcessingException('pdf_page_geometry_locator_invalid');
        }
        if (is_string($geometryPath)) {
            $geometryBytes = $context->locator['geometry_artifact_bytes'] ?? null;
            $geometrySha256 = $context->locator['geometry_artifact_sha256'] ?? null;
            if (! is_int($geometryBytes) || ! is_string($geometrySha256)) {
                throw new DocumentUnitProcessingException('pdf_page_geometry_locator_invalid');
            }
            $geometryContent = $this->reader->read(
                $context->organizationId,
                $geometryPath,
                max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
                $geometryBytes,
                $geometrySha256,
            )->body;
            $decoded = json_decode($geometryContent, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1
                || ! is_string($decoded['text'] ?? null)
                || ! is_array($decoded['geometry'] ?? null)
                || ! is_array($decoded['sources'] ?? null)
                || ! is_array($decoded['provenance'] ?? null)) {
                throw new DocumentUnitProcessingException('pdf_page_geometry_contract_invalid');
            }
            $pdfGeometry = $decoded;
            $nativePdfText = $decoded['text'];
        }
        $routing = $this->sheetAnalysisRouter?->route($analysis, $nativePdfText);
        $targetedRouting = null;
        if ($routing?->classification->requiresTargetedReanalysis()) {
            $targetedRouting = $routing->toArray();
            $targetedOperation = SheetAnalysisOperationIdentity::targeted(
                $context->sessionId, $context->documentId, $context->unitId, $context->sourceVersion, $preprocessed->derivativeHash,
                $targetedRouting,
            );
            try {
                $targetedInput = new VisionDocumentInput(
                    organizationId: $input->organizationId,
                    projectId: $input->projectId,
                    sessionId: $input->sessionId,
                    documentId: $input->documentId,
                    pageId: $input->pageId,
                    pageNumber: $input->pageNumber,
                    processingUnitId: $input->processingUnitId,
                    sourceVersion: $input->sourceVersion,
                    derivativeHash: $input->derivativeHash,
                    contentType: $input->contentType,
                    imageContent: $input->imageContent,
                    imageDetail: $input->imageDetail,
                    operationContext: new AiOperationContext(
                        correlationId: $targetedOperation,
                        attemptId: $targetedOperation,
                        organizationId: $input->operationContext->organizationId,
                        projectId: $input->operationContext->projectId,
                        sessionId: $input->operationContext->sessionId,
                        stage: $input->operationContext->stage,
                        operation: 'vision',
                        attemptOrdinal: 2,
                        documentId: $input->operationContext->documentId,
                        pageId: $input->operationContext->pageId,
                        unitId: $input->operationContext->unitId,
                    ),
                    sourceTransform: $input->sourceTransform,
                    sheetRole: $routing->classification->role->value,
                    recheckScope: TargetedSheetRecheckScope::forEntity(
                        $routing->classification->role->value,
                        (string) $routing->classification->reanalysisReason,
                        'sheet-role:'.$routing->classification->role->value,
                        sprintf('document:%d/sheet:%d', $input->documentId, $input->pageId),
                    ),
                );
                $targetedRun = $this->sheetAnalysisJournal?->run($targetedOperation, 'targeted', $scope, $targetedRouting,
                    function () use ($context, $targetedInput, $preprocessed) {
                        $context->renewLeaseOrFail();

                        return $this->vision->analyze($targetedInput)->mapPolygonsToSource($preprocessed->transform);
                    });
                if ($targetedRun?->analysis === null) {
                    $targetedRouting['outcome'] = 'needs_review';
                    $targetedRouting['needs_review'] = true;
                } else {
                    if ($targetedRun !== null) {
                        $analysis = $targetedRun->analysis;
                    } else {
                        $context->renewLeaseOrFail();
                        $analysis = $this->vision->analyze($targetedInput)->mapPolygonsToSource($preprocessed->transform);
                    }
                    $final = $this->sheetAnalysisRouter?->route($analysis, $nativePdfText);
                    $targetedRouting = $final?->toArray() ?? $targetedRouting;
                    $targetedRouting['outcome'] = $targetedRun?->outcome ?? 'succeeded';
                    $this->sheetAnalysisJournal?->persistFinalRouting($targetedOperation, $scope, $targetedRouting);
                }
            } catch (Throwable $exception) {
                $noCall = $exception instanceof VisionProviderException && $exception->reason === 'vision_wire_replay_forbidden';
                if ($noCall) {
                    $targetedRouting['outcome'] = 'needs_review';
                    $targetedRouting['needs_review'] = true;
                }
                if (! $noCall) {
                    throw $exception;
                }
            }
        }
        $payload = $analysis->toArray();
        $geometryConfidence = $analysis->elements === []
            ? null
            : min(array_map(static fn ($element): float => $element->confidence, $analysis->elements));
        $hardGeometryWarnings = array_values(array_intersect($analysis->warnings, [
            'scale_missing',
            'scale_conflict',
            'perspective_confirmation_required',
            'geometry_incomplete',
        ]));
        $finalRouting = $this->sheetAnalysisRouter?->route($analysis, $nativePdfText);
        $routingPayload = $targetedRouting ?? $finalRouting?->toArray() ?? $routing?->toArray();
        if ($routingPayload !== null && $finalRouting?->classification->requiresTargetedReanalysis()) {
            $routingPayload['outcome'] = 'needs_review';
            $routingPayload['exhausted_reason'] = $finalRouting->classification->reanalysisReason;
        }

        return new DocumentUnitOutput(
            version: hash('sha256', json_encode([
                'vision_analysis' => $payload,
                'sheet_analysis_routing' => $routingPayload,
                'pdf_native_text' => $nativePdfText,
            ], JSON_THROW_ON_ERROR)),
            text: $nativePdfText ?? implode("\n", array_values(array_filter(array_map(
                static fn (array $element): string => trim((string) ($element['label'] ?? '')),
                $payload['elements'],
            )))),
            confidence: $analysis->warnings === [] ? 1.0 : 0.7,
            normalizedPayload: [
                'schema_version' => 1,
                'source_kind' => $provenance->sourceKind,
                'source' => $provenance->toArray(),
                'vision_analysis' => $payload,
                'sheet_analysis_routing' => $routingPayload,
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
            qualitySignals: [
                'sheet_analysis_routing' => $routingPayload ?? [
                    'role' => 'unknown',
                    'needs_review' => false,
                    'outcome' => 'not_applicable',
                ],
                'geometry' => [
                    'confidence' => $geometryConfidence,
                    'hard_blockers' => $hardGeometryWarnings,
                ],
            ],
        );
    }

    private function withSourceProvenance(DocumentUnitOutput $output, DocumentUnitProvenance $provenance): DocumentUnitOutput
    {
        return new DocumentUnitOutput(
            version: $output->version,
            text: $output->text,
            confidence: $output->confidence,
            normalizedPayload: [...$output->normalizedPayload, 'source' => $provenance->toArray()],
            width: $output->width,
            height: $output->height,
            rotation: $output->rotation,
            unitType: $output->unitType,
            unitIndex: $output->unitIndex,
            sourceVersion: $output->sourceVersion,
            qualitySignals: $output->qualitySignals,
        );
    }
}
