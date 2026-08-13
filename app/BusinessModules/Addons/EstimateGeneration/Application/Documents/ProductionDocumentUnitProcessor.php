<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\DocumentSheetOperationScope;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationJournal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRouter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\TargetedSheetEvidenceResolver;
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
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetRecheckPlanner;
use App\Models\Organization;
use InvalidArgumentException;
use JsonException;
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
        private ?CadRepresentationPublisher $cadRepresentationPublisher = null,
        private ?TargetedSheetEvidenceResolver $targetedEvidenceResolver = null,
        private TargetedSheetRecheckPlanner $targetedRecheckPlanner = new TargetedSheetRecheckPlanner,
        private DocumentRepresentationResourceMeter $resourceMeter = new SystemDocumentRepresentationResourceMeter,
    ) {}

    public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        try {
            $measurement = $this->resourceMeter->measure(fn (): DocumentUnitOutput => $this->processMeasured($context));
            if (! $measurement->result instanceof DocumentUnitOutput) {
                throw new DocumentUnitProcessingException('document_representation_measurement_invalid');
            }

            return $this->withMeasuredRepresentation($measurement->result, $measurement);
        } catch (DocumentRepresentationMeasurementException $exception) {
            throw $this->processingFailure(
                $exception->getPrevious() ?? $exception,
                [
                    'duration_ms' => $exception->measurement->durationMs,
                    'peak_memory_bytes' => $exception->measurement->incrementalPeakMemoryBytes,
                    'memory_metric' => $exception->measurement->memoryMetric,
                    'limitations' => $exception->measurement->limitations,
                ],
            );
        } catch (Throwable $exception) {
            throw $this->processingFailure($exception);
        }
    }

    /** @param array<string, mixed> $resourceUsage */
    private function processingFailure(Throwable $exception, array $resourceUsage = []): Throwable
    {
        return match (true) {
            $exception instanceof DocumentUnitProcessingException => $resourceUsage === []
                ? $exception
                : new DocumentUnitProcessingException($exception->safeCode, $exception, $resourceUsage),
            $exception instanceof TypedFailureException => $resourceUsage === []
                ? $exception
                : new TypedFailureException(
                    $exception->category,
                    $exception->safeCode,
                    $exception->safeContext,
                    $exception,
                    $resourceUsage,
                ),
            $exception instanceof S3ObjectLocatorException => new TypedFailureException(
                FailureCategory::Terminal,
                'document_artifact_integrity_failed',
                previous: $exception,
                resourceUsage: $resourceUsage,
            ),
            $exception instanceof S3ObjectTransportException => new TypedFailureException(
                FailureCategory::Recoverable,
                'document_storage_unavailable',
                previous: $exception,
                resourceUsage: $resourceUsage,
            ),
            $exception instanceof GeometryExtractionException => new TypedFailureException(
                $exception->retryable ? FailureCategory::Recoverable : FailureCategory::Terminal,
                $exception->reason,
                $exception->safeContext,
                $exception,
                $resourceUsage,
            ),
            $exception instanceof VisionProviderException => new TypedFailureException(
                $exception->retryable ? FailureCategory::Recoverable : FailureCategory::Terminal,
                $exception->reason,
                $exception->httpCode === null ? [] : ['http_status' => $exception->httpCode],
                $exception,
                $resourceUsage,
            ),
            $exception instanceof DocumentManifestNeedsReview => new TypedFailureException(
                FailureCategory::UserActionRequired,
                $exception->safeCode,
                previous: $exception,
                resourceUsage: $resourceUsage,
            ),
            default => new DocumentUnitProcessingException('document_unit_processing_failed', $exception, $resourceUsage),
        };
    }

    private function processMeasured(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        $provenance = DocumentUnitProvenance::fromLocator($context->type, $context->sourceVersion, $context->locator);
        $output = match (true) {
            $context->type === DocumentUnitType::PdfPage && ($context->locator['content_type'] ?? null) === 'image/png' => $this->processRaster($context, $provenance),
            $context->type === DocumentUnitType::CadDrawing => $this->processCad($context, $provenance),
            $context->type === DocumentUnitType::RasterImage, $context->type === DocumentUnitType::Sketch => $this->processRaster($context, $provenance),
            default => $this->ocr->process($context),
        };

        return $this->withSourceProvenance($this->withCanonicalRepresentation($output, $context), $provenance);
    }

    private function withMeasuredRepresentation(
        DocumentUnitOutput $output,
        DocumentRepresentationMeasurement $measurement,
    ): DocumentUnitOutput {
        $serialized = $output->normalizedPayload['document_representation'] ?? null;
        if (! is_array($serialized)) {
            return $output;
        }
        $previousUsage = is_array($serialized['resource_usage'] ?? null) ? $serialized['resource_usage'] : [];
        $serialized['resource_usage']['duration_ms'] = max(0, (int) ($previousUsage['duration_ms'] ?? 0))
            + $measurement->durationMs;
        $serialized['resource_usage']['peak_memory_bytes'] = max(
            max(0, (int) ($previousUsage['peak_memory_bytes'] ?? 0)),
            $measurement->incrementalPeakMemoryBytes,
        );
        $native = is_array($serialized['native_structure'] ?? null) ? $serialized['native_structure'] : [];
        $previousMeasurement = is_array($native['resource_measurement'] ?? null)
            ? $native['resource_measurement']
            : [];
        $native['resource_measurement'] = [
            'memory_metric' => $measurement->memoryMetric,
            'limitations' => array_values(array_unique(array_merge(
                is_array($previousMeasurement['limitations'] ?? null) ? $previousMeasurement['limitations'] : [],
                $measurement->limitations,
            ))),
            'phases' => ['adapter_representation', 'processor'],
        ];
        $serialized['native_structure'] = $native;
        $representation = DocumentRepresentation::fromArray($serialized);

        return new DocumentUnitOutput(
            version: $output->version,
            text: $output->text,
            confidence: $output->confidence,
            normalizedPayload: [...$output->normalizedPayload, 'document_representation' => $representation->toArray()],
            width: $output->width,
            height: $output->height,
            rotation: $output->rotation,
            unitType: $output->unitType,
            unitIndex: $output->unitIndex,
            sourceVersion: $output->sourceVersion,
            qualitySignals: $output->qualitySignals,
        );
    }

    private function processCad(DocumentUnitExecutionContext $context, DocumentUnitProvenance $provenance): DocumentUnitOutput
    {
        $organization = new Organization;
        $organization->id = $context->organizationId;
        $geometry = $this->cad->extract($provenance, $organization);
        $payload = $geometry->toArray();
        $representation = $this->cadRepresentationPublisher?->publish($geometry, $context);
        if ($representation !== null) {
            foreach (['layers', 'blocks', 'polylines', 'dimensions', 'texts', 'sheet_render', 'source_coordinates'] as $capability) {
                $representation->capabilities->assertAvailable($capability);
            }
        }
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
                ...($representation === null ? [] : ['document_representation' => $representation->toArray()]),
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
        $auxiliary = $this->pdfAuxiliary($context);
        $nativeReferences = $auxiliary['representation'] instanceof DocumentRepresentation
            ? $this->representationNativeReferences($auxiliary['representation'])
            : [];
        $nativePdfText = $auxiliary['native_text'];
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
            preserveColor: $context->type === DocumentUnitType::PdfPage,
        ));
        $image = $this->reader->read(
            $context->organizationId,
            $preprocessed->derivativeStorageKey,
            max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000)),
            $preprocessed->derivativeBytes,
            $preprocessed->derivativeHash,
        )->body;
        $auxiliaryText = is_string($nativePdfText) ? mb_substr($nativePdfText, 0, 12_000) : null;
        $auxiliaryMetadata = $this->visionAuxiliaryMetadata($auxiliary, $preprocessed, $nativePdfText);
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
            nativeReferences: $nativeReferences,
            auxiliaryText: $auxiliaryText,
            auxiliaryMetadata: $auxiliaryMetadata,
        );
        $scope = new DocumentSheetOperationScope($context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->unitId, $context->sourceVersion, $context->claimToken);
        $primaryRouting = ['role' => 'unknown', 'needs_review' => false, 'outcome' => 'not_applicable'];
        $primaryRun = $this->sheetAnalysisJournal?->run($correlationId, 'primary', $scope, $primaryRouting,
            function () use ($context, $input) {
                $context->renewLeaseOrFail();

                return $this->vision->analyze($input);
            });
        if ($primaryRun !== null) {
            $analysis = $primaryRun->analysis;
        } else {
            $context->renewLeaseOrFail();
            $analysis = $this->vision->analyze($input);
        }
        if ($analysis === null) {
            throw new DocumentUnitProcessingException('sheet_analysis_requires_review');
        }
        $routing = $this->sheetAnalysisRouter?->route($analysis, $nativePdfText);
        $targetedRouting = null;
        if ($routing?->classification->requiresTargetedReanalysis()) {
            $targetedRouting = $routing->toArray();
            $peerEvidence = $routing->classification->reanalysisReason === 'sheet_role_conflict'
                ? $this->targetedEvidenceResolver?->resolvePeer($context, $routing->classification->role->value)
                : null;
            $targetedPlan = $this->targetedRecheckPlanner->plan(
                $context->documentId,
                $context->pageId,
                $routing,
                $analysis,
                $peerEvidence,
            );
            if ($targetedPlan === null) {
                $targetedRouting['outcome'] = 'needs_review';
                $targetedRouting['needs_review'] = true;

                return $this->rasterOutput(
                    $context,
                    $input,
                    $preprocessed,
                    $analysis,
                    $auxiliary,
                    $targetedRouting,
                    $provenance,
                    $routing,
                );
            }
            $targetedRouting['targeted_scope'] = $targetedPlan->scope->toSafeUsageContext();
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
                    recheckScope: $targetedPlan->scope,
                    nativeReferences: $input->nativeReferences,
                    supplementalEvidence: $targetedPlan->supplementalEvidence === null
                        ? []
                        : [$targetedPlan->supplementalEvidence],
                    auxiliaryText: $input->auxiliaryText,
                    auxiliaryMetadata: $input->auxiliaryMetadata,
                    primaryAnalysis: $analysis,
                );
                $targetedRun = $this->sheetAnalysisJournal?->run(
                    $targetedOperation,
                    'targeted',
                    $scope,
                    $targetedRouting,
                    function () use ($context, $targetedInput) {
                        $context->renewLeaseOrFail();

                        return $this->vision->analyze($targetedInput);
                    },
                );
                if ($this->sheetAnalysisJournal !== null && $targetedRun?->analysis === null) {
                    $targetedRouting['outcome'] = 'needs_review';
                    $targetedRouting['needs_review'] = true;
                } else {
                    if ($targetedRun === null) {
                        $context->renewLeaseOrFail();
                        $analysis = $this->vision->analyze($targetedInput);
                    } else {
                        $analysis = $targetedRun->analysis;
                    }
                    $final = $this->sheetAnalysisRouter?->route($analysis, $nativePdfText);
                    $targetedRouting = [...$targetedRouting, ...($final?->toArray() ?? [])];
                    $targetedRouting['outcome'] = $targetedRun?->outcome ?? 'succeeded';
                    $this->sheetAnalysisJournal?->persistFinalRouting($targetedOperation, $scope, $targetedRouting);
                }
            } catch (VisionContractException|VisionProviderException $exception) {
                $targetedRouting['outcome'] = 'needs_review';
                $targetedRouting['needs_review'] = true;
                $targetedRouting['limitation_code'] = $exception->reason;
            }
        }

        return $this->rasterOutput(
            $context,
            $input,
            $preprocessed,
            $analysis,
            $auxiliary,
            $targetedRouting,
            $provenance,
            $routing,
        );
    }

    /**
     * @return array{
     *     representation: ?DocumentRepresentation,
     *     geometry: ?array<string, mixed>,
     *     native_text: ?string,
     *     representation_status: string,
     *     geometry_status: string
     * }
     */
    private function pdfAuxiliary(DocumentUnitExecutionContext $context): array
    {
        if ($context->type !== DocumentUnitType::PdfPage) {
            return [
                'representation' => null,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => 'not_applicable',
                'geometry_status' => 'not_applicable',
            ];
        }

        $serialized = $context->locator['document_representation'] ?? null;
        if (is_array($serialized)
            && is_string($serialized['source_version'] ?? null)
            && $serialized['source_version'] !== $context->sourceVersion) {
            throw new DocumentUnitProcessingException('document_representation_source_mismatch');
        }
        $representation = null;
        $representationStatus = 'unavailable:document_representation_missing';
        if (is_array($serialized)) {
            try {
                $representation = DocumentRepresentation::fromArray($serialized);
                $representationStatus = 'available';
            } catch (InvalidArgumentException) {
                $representationStatus = 'unavailable:document_representation_contract_invalid';
            }
        }

        $geometryPath = $context->locator['geometry_artifact_path'] ?? null;
        if (! is_string($geometryPath)) {
            return [
                'representation' => $representation,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => $representationStatus,
                'geometry_status' => 'unavailable:pdf_geometry_missing',
            ];
        }

        $geometryBytes = $context->locator['geometry_artifact_bytes'] ?? null;
        $geometrySha256 = $context->locator['geometry_artifact_sha256'] ?? null;
        if (! is_int($geometryBytes) || ! is_string($geometrySha256)) {
            return [
                'representation' => $representation,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => $representationStatus,
                'geometry_status' => 'unavailable:pdf_geometry_locator_invalid',
            ];
        }
        try {
            $geometryContent = $this->reader->read(
                $context->organizationId,
                $geometryPath,
                max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
                $geometryBytes,
                $geometrySha256,
            )->body;
        } catch (S3ObjectLocatorException) {
            return [
                'representation' => $representation,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => $representationStatus,
                'geometry_status' => 'unavailable:pdf_geometry_integrity_failed',
            ];
        } catch (S3ObjectTransportException) {
            return [
                'representation' => $representation,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => $representationStatus,
                'geometry_status' => 'unavailable:pdf_geometry_storage_unavailable',
            ];
        }
        try {
            $decoded = json_decode($geometryContent, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1
            || ! is_string($decoded['text'] ?? null)
            || ! is_array($decoded['geometry'] ?? null)
            || ! is_array($decoded['sources'] ?? null)
            || ! is_array($decoded['provenance'] ?? null)) {
            return [
                'representation' => $representation,
                'geometry' => null,
                'native_text' => null,
                'representation_status' => $representationStatus,
                'geometry_status' => 'unavailable:pdf_geometry_contract_invalid',
            ];
        }

        return [
            'representation' => $representation,
            'geometry' => $decoded,
            'native_text' => $decoded['text'],
            'representation_status' => $representationStatus,
            'geometry_status' => 'available',
        ];
    }

    /** @return list<string> */
    private function representationNativeReferences(DocumentRepresentation $representation): array
    {
        $registry = $representation->nativeStructure['native_reference_registry'] ?? null;

        return is_array($registry) ? array_values(array_filter($registry, 'is_string')) : [];
    }

    /**
     * @param array{
     *     representation: ?DocumentRepresentation,
     *     geometry: ?array<string, mixed>,
     *     native_text: ?string,
     *     representation_status: string,
     *     geometry_status: string
     * } $auxiliary
     * @return array<string, mixed>
     */
    private function visionAuxiliaryMetadata(
        array $auxiliary,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult $preprocessed,
        ?string $nativePdfText,
    ): array {
        $representation = $auxiliary['representation'];

        return [
            'representation_status' => $auxiliary['representation_status'],
            'geometry_status' => $auxiliary['geometry_status'],
            'capabilities' => $representation?->capabilities->toArray() ?? [
                'text_spans' => $nativePdfText === null ? 'unavailable:pdf_text_layer_missing' : 'available',
                'vectors' => 'unavailable:pdf_vectors_missing',
                'page_render' => 'available',
                'source_coordinates' => 'available',
            ],
            'source_bounds' => $representation?->sourceBounds
                ?? [0, 0, $preprocessed->sourceWidth, $preprocessed->sourceHeight],
            'native_text_truncated' => is_string($nativePdfText) && mb_strlen($nativePdfText) > 12_000,
        ];
    }

    /**
     * @param array{
     *     representation: ?DocumentRepresentation,
     *     geometry: ?array<string, mixed>,
     *     native_text: ?string,
     *     representation_status: string,
     *     geometry_status: string
     * } $auxiliary
     * @param  array<string, mixed>|null  $targetedRouting
     */
    private function rasterOutput(
        DocumentUnitExecutionContext $context,
        VisionDocumentInput $input,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult $preprocessed,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData $analysis,
        array $auxiliary,
        ?array $targetedRouting,
        DocumentUnitProvenance $provenance,
        ?\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRoutingResult $routing,
    ): DocumentUnitOutput {
        $payload = $analysis->toArray();
        $rasterRepresentation = null;
        if ($context->type === DocumentUnitType::PdfPage) {
            $rasterRepresentation = $auxiliary['representation']
                ?? $this->pdfRasterRepresentation($context, $preprocessed, $auxiliary);
        } elseif (in_array($context->type, [DocumentUnitType::RasterImage, DocumentUnitType::Sketch], true)) {
            $rasterRepresentation = (new ImageDocumentAdapter)->representation(new DocumentUnitData(
                $context->type,
                $context->index,
                $context->sourceVersion,
                [...$context->locator, 'source_bounds' => [0, 0, $preprocessed->sourceWidth, $preprocessed->sourceHeight]],
            ));
        }
        $geometryConfidence = $analysis->elements === []
            ? null
            : min(array_map(static fn ($element): float => $element->confidence, $analysis->elements));
        $hardGeometryWarnings = array_values(array_intersect($analysis->warnings, [
            'scale_missing',
            'scale_conflict',
            'perspective_confirmation_required',
            'geometry_incomplete',
        ]));
        $nativePdfText = $auxiliary['native_text'];
        $pdfGeometry = $auxiliary['geometry'];
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
                'pdf_geometry' => $pdfGeometry,
                'auxiliary_sources' => [$auxiliary['representation_status'], $auxiliary['geometry_status']],
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
                'auxiliary_sources' => [
                    'document_representation' => ['status' => $auxiliary['representation_status']],
                    'pdf_geometry' => ['status' => $auxiliary['geometry_status']],
                ],
                'preprocessing' => [
                    'version' => $preprocessed->derivativeVersion,
                    'derivative_hash' => $preprocessed->derivativeHash,
                    'derivative_storage_key' => $preprocessed->derivativeStorageKey,
                    'derivative_bytes' => $preprocessed->derivativeBytes,
                    'perspective_status' => $preprocessed->perspectiveStatus,
                    'warnings' => $preprocessed->warnings,
                ],
                ...($rasterRepresentation === null ? [] : ['document_representation' => $rasterRepresentation->toArray()]),
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
                    'evidence_source' => 'vision',
                    'auxiliary_status' => $auxiliary['geometry_status'],
                ],
            ],
        );
    }

    /**
     * @param  array{representation_status: string, geometry_status: string}  $auxiliary
     */
    private function pdfRasterRepresentation(
        DocumentUnitExecutionContext $context,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult $preprocessed,
        array $auxiliary,
    ): DocumentRepresentation {
        $locator = $context->locator;
        unset(
            $locator['document_representation'],
            $locator['geometry_artifact_path'],
            $locator['geometry_artifact_bytes'],
            $locator['geometry_artifact_sha256'],
            $locator['native_reference_registry'],
        );
        $locator['visual_artifact_path'] = $preprocessed->derivativeStorageKey;
        $locator['source_bounds'] = [0, 0, $preprocessed->sourceWidth, $preprocessed->sourceHeight];
        $locator['text_layer_status'] = 'unavailable';
        $locator['object_count'] = 0;
        $locator['representation_bytes'] = $preprocessed->derivativeBytes;

        return (new DocumentRepresentationBuilder)->build(
            'pdf',
            new DocumentUnitData(
                DocumentUnitType::PdfPage,
                $context->index,
                $context->sourceVersion,
                $locator,
            ),
            [
                'geometry_artifact_path' => null,
                'geometry_artifact_sha256' => null,
                'text_spans_artifact_path' => null,
                'vector_artifact_path' => null,
                'native_reference_registry' => [],
                'auxiliary_sources' => $auxiliary,
            ],
            [
                'text_spans' => 'unavailable:pdf_text_layer_missing',
                'vectors' => 'unavailable:pdf_vectors_missing',
                'page_render' => 'available',
                'source_coordinates' => 'available',
            ],
        );
    }

    private function withCanonicalRepresentation(
        DocumentUnitOutput $output,
        DocumentUnitExecutionContext $context,
    ): DocumentUnitOutput {
        if (isset($output->normalizedPayload['document_representation'])) {
            return $output;
        }
        $serialized = $context->locator['document_representation'] ?? null;
        if (! is_array($serialized)) {
            return $output;
        }
        $representation = DocumentRepresentation::fromArray($serialized);
        if ($representation->source->value !== $context->sourceVersion) {
            throw new DocumentUnitProcessingException('document_representation_source_mismatch');
        }

        return new DocumentUnitOutput(
            version: $output->version,
            text: $output->text,
            confidence: $output->confidence,
            normalizedPayload: [...$output->normalizedPayload, 'document_representation' => $representation->toArray()],
            width: $output->width,
            height: $output->height,
            rotation: $output->rotation,
            unitType: $output->unitType,
            unitIndex: $output->unitIndex,
            sourceVersion: $output->sourceVersion,
            qualitySignals: $output->qualitySignals,
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
