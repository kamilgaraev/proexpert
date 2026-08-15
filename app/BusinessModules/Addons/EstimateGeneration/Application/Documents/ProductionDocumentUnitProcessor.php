<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\DocumentArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\DocumentObserverRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\ObserverDisagreementDetector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisPlan;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoutingDecision;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Cad\CadStructureExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionCropper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor;
use App\Models\Organization;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class ProductionDocumentUnitProcessor implements DocumentUnitProcessor
{
    public function __construct(
        private OcrDocumentUnitProcessor $ocr,
        private CadGeometryProvider $cad,
        private RasterPreprocessor $raster,
        private BoundedVersionedS3ObjectReader $reader,
        private DocumentObserverRunner $independentObservers,
        private DocumentArbitrator $documentArbitration,
        private DocumentUnitPublicationFactory $publicationFactory = new DocumentUnitPublicationFactory,
        private CadStructureExtractor $cadStructure = new CadStructureExtractor,
        private ?CadRepresentationPublisher $cadRepresentationPublisher = null,
        private DocumentRepresentationResourceMeter $resourceMeter = new SystemDocumentRepresentationResourceMeter,
        private SemanticRegionIngestor $semanticRegions = new SemanticRegionIngestor,
        private SemanticRegionCropper $semanticRegionCropper = new SemanticRegionCropper,
        private ObserverDisagreementDetector $observerDisagreement = new ObserverDisagreementDetector,
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
                $context,
                [
                    'duration_ms' => $exception->measurement->durationMs,
                    'peak_memory_bytes' => $exception->measurement->incrementalPeakMemoryBytes,
                    'memory_metric' => $exception->measurement->memoryMetric,
                    'limitations' => $exception->measurement->limitations,
                ],
            );
        } catch (Throwable $exception) {
            throw $this->processingFailure($exception, $context);
        }
    }

    /** @param array<string, mixed> $resourceUsage */
    private function processingFailure(
        Throwable $exception,
        DocumentUnitExecutionContext $context,
        array $resourceUsage = [],
    ): Throwable {
        return match (true) {
            $exception instanceof DocumentUnitProcessingException => $resourceUsage === []
                ? $exception
                : new DocumentUnitProcessingException($exception->safeCode, $exception, $resourceUsage),
            $exception instanceof TypedFailureException => $resourceUsage === []
                ? new TypedFailureException(
                    $exception->category,
                    $exception->safeCode,
                    [...$this->boundaryContext($context), ...$exception->safeContext],
                    $exception->getPrevious() ?? $exception,
                    $exception->resourceUsage,
                )
                : new TypedFailureException(
                    $exception->category,
                    $exception->safeCode,
                    [...$this->boundaryContext($context), ...$exception->safeContext],
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
            $exception instanceof VisionContractException => new TypedFailureException(
                FailureCategory::Terminal,
                'vision_provider_response_invalid',
                [
                    ...$this->boundaryContext($context),
                    'execution_boundary' => 'vision_provider_response_parsing',
                ],
                $exception,
                $resourceUsage,
            ),
            $exception instanceof VisionProviderException => new TypedFailureException(
                $exception->retryable ? FailureCategory::Recoverable : FailureCategory::Terminal,
                $exception->reason,
                [
                    ...$exception->safeContext,
                    ...($exception->httpCode === null ? [] : ['http_status' => $exception->httpCode]),
                ],
                $exception,
                $resourceUsage,
            ),
            $exception instanceof DocumentManifestNeedsReview => new TypedFailureException(
                FailureCategory::UserActionRequired,
                $exception->safeCode,
                previous: $exception,
                resourceUsage: $resourceUsage,
            ),
            default => new TypedFailureException(
                FailureCategory::Terminal,
                'document_unit_pre_wire_failed',
                $this->boundaryContext($context),
                $exception,
                $resourceUsage,
            ),
        };
    }

    /** @return array<string, string> */
    private function boundaryContext(DocumentUnitExecutionContext $context): array
    {
        return [
            'execution_boundary' => 'document_unit_representation',
            ...(preg_match('/\A[0-9a-f-]{36}\z/i', $context->processingAttemptId) === 1
                ? ['processing_attempt_id' => strtolower($context->processingAttemptId)]
                : []),
        ];
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
            'phases' => array_values(array_unique([
                'adapter_representation',
                'processor',
                ...(is_array($previousMeasurement['phases'] ?? null) ? $previousMeasurement['phases'] : []),
            ])),
        ];
        $serialized['native_structure'] = $native;
        $representation = DocumentRepresentation::fromArray($serialized);
        $workflowUsage = [
            'duration_ms' => $measurement->durationMs,
            'peak_memory_bytes' => $measurement->incrementalPeakMemoryBytes,
            'memory_metric' => $measurement->memoryMetric,
            'limitations' => $measurement->limitations,
            'terminal_response_preserved' => true,
        ];

        return new DocumentUnitOutput(
            version: $output->version,
            text: $output->text,
            confidence: $output->confidence,
            normalizedPayload: [
                ...$output->normalizedPayload,
                'document_representation' => $representation->toArray(),
                'page_workflow_usage' => $workflowUsage,
            ],
            width: $output->width,
            height: $output->height,
            rotation: $output->rotation,
            unitType: $output->unitType,
            unitIndex: $output->unitIndex,
            sourceVersion: $output->sourceVersion,
            qualitySignals: $output->qualitySignals,
            publication: $output->publication,
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
                'page_number' => $context->index,
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
        $correlationId = AiOperationContext::deterministicId(implode('|', [
            'document-observation',
            $context->sessionId,
            $context->documentId,
            $context->unitId,
            $context->sourceVersion,
            $preprocessed->derivativeHash,
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
                processingLineageId: preg_match('/\A[0-9a-f-]{36}\z/i', $context->processingAttemptId) === 1
                    ? strtolower($context->processingAttemptId)
                    : null,
            ),
            sourceTransform: $preprocessed->transform,
            nativeReferences: $nativeReferences,
            auxiliaryText: $auxiliaryText,
            auxiliaryMetadata: $auxiliaryMetadata,
        );
        $context->renewLeaseOrFail();
        $observerResults = $this->independentObservers->run($input, [ObserverProfile::Literal]);
        $literalRouting = $observerResults['observer_literal']->payload['observation']['analysis_routing'] ?? null;
        try {
            $routing = is_array($literalRouting)
                ? PageAnalysisRoutingDecision::fromProviderArray($literalRouting)
                : PageAnalysisRoutingDecision::failOpen('literal_routing_missing');
        } catch (InvalidArgumentException) {
            $routing = PageAnalysisRoutingDecision::failOpen('literal_routing_invalid');
        }
        $plan = PageAnalysisPlan::fromDecision($routing);
        if (($context->locator['analysis_escalation_reason'] ?? null) === 'cross_document_reference') {
            $plan = $plan->escalateForCrossDocumentReference();
        }
        $this->assertAnalysisPlanBudget($plan);
        $regionSourceImage = $image;
        if ($plan->usesSemanticRegions && $routing->semanticRegions !== []) {
            $regionSourceImage = $this->reader->read(
                $context->organizationId,
                $storageKey,
                max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000)),
                $sourceBytes,
                $sourceSha256,
            )->body;
        }
        $imageDimensions = getimagesizefromstring($regionSourceImage);
        if (! is_array($imageDimensions)) {
            throw new DocumentUnitProcessingException('vision_page_image_invalid');
        }
        $regionSet = $this->semanticRegions->ingest(
            $plan->usesSemanticRegions ? $routing->semanticRegions : [],
            (int) $imageDimensions[0],
            (int) $imageDimensions[1],
        );
        if ($plan->usesSemanticRegions && $regionSet->regions !== []) {
            $regionImages = $this->semanticRegionCropper->crop($regionSourceImage, $regionSet);
            $regionSet = $this->renderedRegionSet($regionSet, $regionImages);
            $input = $input->withRegionImages($regionImages);
        }
        unset($regionSourceImage);
        $remainingProfiles = array_values(array_filter(
            $plan->observers,
            static fn (ObserverProfile $profile): bool => $profile !== ObserverProfile::Literal,
        ));
        if ($remainingProfiles !== []) {
            $context->renewLeaseOrFail();
            $observerResults = [
                ...$observerResults,
                ...$this->independentObservers->run($input, $remainingProfiles),
            ];
        }
        if ($plan->route->value === 'structured_textual') {
            $plan = PageAnalysisPlan::fromDecision(
                $routing,
                $this->observerDisagreement->hasMaterialDisagreement($observerResults),
            );
            $this->assertAnalysisPlanBudget($plan);
        }
        $arbitrationResult = null;
        if ($plan->requiresArbitration) {
            $context->renewLeaseOrFail();
            $arbitrationResult = $this->documentArbitration->run($input, $observerResults);
        }

        return $this->rasterOutput(
            $context,
            $preprocessed,
            $auxiliary,
            $provenance,
            $observerResults,
            $arbitrationResult,
            $plan,
            $regionSet,
            $this->publicationFactory->fromAnalysis($input, $observerResults, $arbitrationResult),
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
     * @param  array<string, AiRoleRunResult>  $observerResults
     */
    private function rasterOutput(
        DocumentUnitExecutionContext $context,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult $preprocessed,
        array $auxiliary,
        DocumentUnitProvenance $provenance,
        array $observerResults,
        ?AiRoleRunResult $arbitrationResult,
        PageAnalysisPlan $analysisPlan,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet $semanticRegionSet,
        ?DocumentUnitPublication $publication,
    ): DocumentUnitOutput {
        $observerPayloads = array_map(static fn (AiRoleRunResult $result): array => $result->payload, $observerResults);
        $literalPayload = $observerPayloads['observer_literal']['observation'] ?? null;
        if (! is_array($literalPayload)) {
            throw new DocumentUnitProcessingException('literal_observer_result_missing');
        }
        $payload = [
            'schema_version' => 4,
            'sheet_type' => $literalPayload['sheet_type'] ?? 'unknown',
            'elements' => is_array($literalPayload['elements'] ?? null) ? $literalPayload['elements'] : [],
            'visual_attributes' => is_array($literalPayload['visual_attributes'] ?? null) ? $literalPayload['visual_attributes'] : [],
            'warnings' => is_array($literalPayload['warnings'] ?? null) ? $literalPayload['warnings'] : [],
            'quarantined_items' => [
                ...(is_array($literalPayload['quarantined_items'] ?? null) ? $literalPayload['quarantined_items'] : []),
                ...($publication?->quarantinedItems ?? []),
            ],
            'arbitration_decisions' => is_array($arbitrationResult?->payload['decisions'] ?? null)
                ? $arbitrationResult->payload['decisions']
                : [],
        ];
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
        $hardGeometryWarnings = array_values(array_intersect($payload['warnings'], [
            'scale_missing',
            'scale_conflict',
            'perspective_confirmation_required',
            'geometry_incomplete',
        ]));
        $nativePdfText = $auxiliary['native_text'];
        $pdfGeometry = $auxiliary['geometry'];
        $questions = is_array($arbitrationResult?->payload['questions'] ?? null)
            ? $arbitrationResult->payload['questions']
            : [];
        $roleCompletion = [];
        foreach ($analysisPlan->observers as $profile) {
            $role = $profile->role()->value;
            $roleCompletion[$role] = isset($observerPayloads[$role]);
        }
        if ($analysisPlan->requiresArbitration) {
            $roleCompletion['arbiter'] = ($arbitrationResult?->payload['role'] ?? null) === 'arbiter';
        }
        $analysisOutcome = $this->analysisOutcome(
            $analysisPlan,
            $observerPayloads,
            $arbitrationResult,
            $questions,
            $semanticRegionSet,
        );

        return new DocumentUnitOutput(
            version: hash('sha256', json_encode([
                'vision_analysis' => $payload,
                'role_completion' => $roleCompletion,
                'pdf_native_text' => $nativePdfText,
                'pdf_geometry' => $pdfGeometry,
                'auxiliary_sources' => [$auxiliary['representation_status'], $auxiliary['geometry_status']],
                'independent_observations' => array_map(
                    static fn (AiRoleRunResult $result): array => $result->payload,
                    $observerResults,
                ),
                'document_arbitration' => $arbitrationResult?->payload,
                'analysis_routing' => $this->analysisRoutingPayload(
                    $analysisPlan,
                    $semanticRegionSet,
                    $observerResults,
                    $arbitrationResult,
                ),
                'analysis_outcome' => $analysisOutcome,
                'ai_questions' => $questions,
            ], JSON_THROW_ON_ERROR)),
            text: $nativePdfText ?? implode("\n", array_values(array_filter(array_map(
                static fn (array $element): string => trim((string) ($element['label'] ?? '')),
                $payload['elements'],
            )))),
            confidence: 1.0,
            normalizedPayload: [
                'schema_version' => 4,
                'page_number' => $context->index,
                'source_kind' => $provenance->sourceKind,
                'source' => $provenance->toArray(),
                'vision_analysis' => $payload,
                'role_completion' => $roleCompletion,
                'pdf_geometry' => $pdfGeometry,
                'auxiliary_sources' => [
                    'document_representation' => ['status' => $auxiliary['representation_status']],
                    'pdf_geometry' => ['status' => $auxiliary['geometry_status']],
                ],
                'independent_observations' => array_map(
                    static fn (AiRoleRunResult $result): array => $result->payload,
                    $observerResults,
                ),
                'document_arbitration' => $arbitrationResult?->payload,
                'analysis_routing' => $this->analysisRoutingPayload(
                    $analysisPlan,
                    $semanticRegionSet,
                    $observerResults,
                    $arbitrationResult,
                ),
                'analysis_outcome' => $analysisOutcome,
                'ai_questions' => $questions,
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
                    'source_version' => $context->sourceVersion,
                ],
            ],
            width: $preprocessed->sourceWidth,
            height: $preprocessed->sourceHeight,
            unitType: $context->type,
            unitIndex: $context->index,
            sourceVersion: $context->sourceVersion,
            qualitySignals: [
                'role_completion' => $roleCompletion,
                'unresolved_question_count' => count($questions),
                'geometry' => [
                    'hard_blockers' => $hardGeometryWarnings,
                    'evidence_source' => 'arbitrated_observation',
                    'auxiliary_status' => $auxiliary['geometry_status'],
                ],
            ],
            publication: $publication,
        );
    }

    private function assertAnalysisPlanBudget(PageAnalysisPlan $plan): void
    {
        $maximum = max(1, (int) config('estimate-generation.vision.adaptive_analysis.max_provider_calls_per_page', 4));
        if ($plan->providerCallCount() > $maximum) {
            throw new DocumentUnitProcessingException('page_analysis_provider_call_budget_exceeded');
        }
    }

    /** @param list<array<string, mixed>> $images */
    private function renderedRegionSet(
        \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet $proposed,
        array $images,
    ): \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet {
        $renderedIds = array_fill_keys(array_values(array_filter(array_column($images, 'id'), 'is_string')), true);
        $rendered = [];
        $quarantined = $proposed->quarantined;
        foreach ($proposed->regions as $index => $region) {
            if (isset($renderedIds[$region->id])) {
                $rendered[] = $region;

                continue;
            }
            $quarantined[] = ['index' => $index, 'reason' => 'region_render_budget_exceeded'];
        }

        return new \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet(
            $rendered,
            $quarantined,
            array_sum(array_map(static fn ($region): int => $region->pixelCount, $rendered)),
        );
    }

    /** @param array<string, array<string, mixed>> $observerPayloads @param list<mixed> $questions */
    private function analysisOutcome(
        PageAnalysisPlan $plan,
        array $observerPayloads,
        ?AiRoleRunResult $arbitration,
        array $questions,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet $semanticRegions,
    ): string {
        if ($questions !== []) {
            return 'partial_review';
        }
        foreach ($observerPayloads as $payload) {
            $quarantined = $payload['observation']['quarantined_items'] ?? [];
            if (is_array($quarantined) && $quarantined !== []) {
                return 'partial_review';
            }
        }
        if ($semanticRegions->quarantined !== []
            || ($plan->route->value === 'dense_ambiguous' && $semanticRegions->regions === [])) {
            return 'partial_review';
        }
        if (in_array($arbitration?->payload['result_state'] ?? null, ['partial', 'questions'], true)) {
            return 'partial_review';
        }
        $decisions = is_array($arbitration?->payload['decisions'] ?? null)
            ? $arbitration->payload['decisions']
            : [];
        foreach ($decisions as $decision) {
            if (is_array($decision) && in_array($decision['status'] ?? null, ['candidate', 'unresolved'], true)) {
                return 'partial_review';
            }
        }

        return $plan->successfulOutcome();
    }

    /**
     * @param  array<string, AiRoleRunResult>  $observerResults
     * @return array<string, mixed>
     */
    private function analysisRoutingPayload(
        PageAnalysisPlan $plan,
        \App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionSet $regions,
        array $observerResults,
        ?AiRoleRunResult $arbitrationResult,
    ): array {
        $physicalAttemptIds = array_values(array_unique(array_filter([
            ...array_map(
                static fn (AiRoleRunResult $result): ?string => $result->physicalAttemptId,
                $observerResults,
            ),
            $arbitrationResult?->physicalAttemptId,
        ], 'is_string')));

        return [
            'route' => $plan->route->value,
            'reasons' => $plan->routingReasons,
            'observer_roles' => array_map(
                static fn (ObserverProfile $profile): string => $profile->role()->value,
                $plan->observers,
            ),
            'arbiter_required' => $plan->requiresArbitration,
            'semantic_regions_used' => $plan->usesSemanticRegions,
            'semantic_region_count' => count($regions->regions),
            'semantic_region_pixels' => $regions->aggregatePixels,
            'semantic_regions' => array_map(static fn ($region): array => [
                'id' => $region->id,
                'label' => $region->label,
                'purpose' => $region->purpose,
                'box' => $region->box,
            ], $regions->regions),
            'semantic_region_quarantine' => $regions->quarantined,
            'physical_provider_call_count' => count($physicalAttemptIds),
            'physical_provider_attempt_ids' => $physicalAttemptIds,
            'planned_provider_call_count' => $plan->providerCallCount(),
            'planned_new_provider_call_count' => $plan->additionalProviderCallCount(),
            'reused_literal_observer' => $plan->reusesLiteralObserver,
        ];
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
            publication: $output->publication,
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
            publication: $output->publication,
        );
    }
}
