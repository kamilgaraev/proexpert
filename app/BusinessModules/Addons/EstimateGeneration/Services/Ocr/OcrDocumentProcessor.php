<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\ExtractedDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentUnderstandingSummaryBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingUnderstandingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryRecognitionMerger;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OcrDocumentProcessor
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly OcrPreflightService $preflightService,
        private readonly OcrClientInterface $ocrClient,
        private readonly DocumentProcessingStatusService $statusService,
        private readonly OcrQualityAnalyzer $qualityAnalyzer,
        private readonly ConstructionDocumentFactExtractor $factExtractor,
        private readonly DocumentFactMerger $factMerger,
        private readonly SpreadsheetDocumentExtractor $spreadsheetExtractor,
        private readonly PdfTextLayerExtractor $pdfTextLayerExtractor,
        private readonly PdfGeometryExtractor $pdfGeometryExtractor,
        private readonly PdfGeometryRecognitionMerger $pdfGeometryRecognitionMerger,
        private readonly DrawingUnderstandingService $drawingUnderstandingService,
        private readonly DocumentUnderstandingSummaryBuilder $documentUnderstandingSummaryBuilder,
        private readonly EstimatorScopeInferenceService $scopeInferenceService,
    ) {}

    public function process(EstimateGenerationDocument $document): EstimateGenerationDocument
    {
        $startedAt = microtime(true);

        try {
            $this->statusService->markProcessing($document, 'preflight', 10);
            $this->preflightService->validateForRecognition($document);
            $content = $this->documentContent($document);
            $pageCount = $this->preflightService->validatePdfPageCount($document, $content);

            if ($pageCount !== null && (int) $document->page_count !== $pageCount) {
                $document->forceFill(['page_count' => $pageCount])->save();
                $document->refresh();
            }

            $provider = $this->preflightService->isSpreadsheet($document)
                ? SpreadsheetDocumentExtractor::PROVIDER
                : ($this->preflightService->isCad($document)
                    ? 'cad_metadata'
                    : (string) config('estimate-generation.ocr.provider', 'timeweb'));
            $this->statusService->markProcessing($document, $this->recognitionStage($document), 35);
            $recognition = $this->recognize($document, $content, $pageCount);

            $this->statusService->markProcessing($document, 'normalization', 75);
            $quality = $this->qualityAnalyzer->analyze($recognition);
            $facts = $this->factExtractor->extract($recognition, (int) $document->id, $document->filename);
            $factsSummary = $this->factMerger->summarize($facts);
            $factsSummary = $this->persistRecognition($document, $recognition, $facts, $factsSummary);

            $requiresDocumentReview = $this->requiresDocumentReview($factsSummary);

            if (in_array($quality->level, ['low', 'unusable'], true) || ($factsSummary['conflicts'] ?? []) !== [] || $requiresDocumentReview) {
                $flags = $quality->flags;

                if (($factsSummary['conflicts'] ?? []) !== []) {
                    $flags[] = 'conflicting_document_facts';
                }

                if ($requiresDocumentReview) {
                    $flags[] = 'document_understanding_requires_review';
                }

                $this->statusService->markNeedsReview($document, $quality->score, array_values(array_unique($flags)), $factsSummary, $quality->level);
            } else {
                $this->statusService->markReady($document, $quality->score, $quality->level, $factsSummary);
            }

            return $document->refresh();
        } catch (OcrProviderException $exception) {
            $this->statusService->markFailed(
                $document,
                $exception->providerCode ?? 'ocr_provider_error',
                $exception->messageKey,
                $exception->context,
            );

            return $document->refresh();
        } catch (Throwable $exception) {
            Log::error('[EstimateGeneration OCR] Document processing failed', [
                'document_id' => $document->id,
                'session_id' => $document->session_id,
                'failure_code' => 'ocr_processing_error',
                'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
            ]);

            $this->statusService->markFailed(
                $document,
                'ocr_processing_error',
                'estimate_generation.ocr_provider_error',
            );

            return $document->refresh();
        }
    }

    private function recognize(EstimateGenerationDocument $document, string $content, ?int $pageCount): OcrRecognitionResult
    {
        if ($this->preflightService->isSpreadsheet($document)) {
            return $this->spreadsheetExtractor->extract($document, $content);
        }

        if ($this->preflightService->isCad($document)) {
            return $this->cadPlaceholderRecognition($document);
        }

        $pdfGeometry = null;

        if ($this->preflightService->isPdf($document)) {
            $pdfGeometry = $this->extractPdfGeometry($content, $document->filename);
            $textLayerRecognition = $this->pdfTextLayerExtractor->extract($content, $document->filename);

            if ($textLayerRecognition instanceof OcrRecognitionResult) {
                return $this->pdfGeometryRecognitionMerger->merge($textLayerRecognition, $pdfGeometry);
            }

            if ($pageCount === null) {
                if ($pdfGeometry instanceof PdfGeometryExtractionResult && $pdfGeometry->pages !== []) {
                    return $this->pdfGeometryRecognitionMerger->fromGeometry($pdfGeometry, $document->filename);
                }

                throw new OcrProviderException(
                    'estimate_generation.ocr_pdf_page_count_unavailable',
                    providerCode: 'pdf_page_count_unavailable',
                );
            }

            $this->statusService->markProcessing($document, 'ocr_request', 45);
        }

        try {
            $recognition = $this->ocrClient->recognize(new OcrDocumentInput(
                content: $content,
                mimeType: $document->mime_type ?? 'application/octet-stream',
                filename: $document->filename,
                pageCount: $pageCount,
                operationContext: $this->operationContext($document),
            ));
        } catch (OcrConfigurationException $exception) {
            if ($pdfGeometry instanceof PdfGeometryExtractionResult && $pdfGeometry->pages !== []) {
                return $this->pdfGeometryRecognitionMerger->fromGeometry($pdfGeometry, $document->filename);
            }

            throw $exception;
        } catch (OcrProviderException $exception) {
            if ($pdfGeometry instanceof PdfGeometryExtractionResult && $pdfGeometry->pages !== []) {
                return $this->pdfGeometryRecognitionMerger->fromGeometry($pdfGeometry, $document->filename);
            }

            throw $exception;
        }

        return $this->pdfGeometryRecognitionMerger->merge($recognition, $pdfGeometry);
    }

    private function extractPdfGeometry(string $content, ?string $filename): ?PdfGeometryExtractionResult
    {
        if (! (bool) config('estimate-generation.ocr.geometry.enabled', true)) {
            return null;
        }

        try {
            return $this->pdfGeometryExtractor->extract($content, $filename);
        } catch (PdfGeometryExtractionException $exception) {
            Log::info('[EstimateGeneration OCR] PDF geometry extraction skipped', [
                'failure_code' => 'drawing_geometry_unreadable',
                'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
            ]);

            return null;
        }
    }

    private function recognitionStage(EstimateGenerationDocument $document): string
    {
        if ($this->preflightService->isSpreadsheet($document)) {
            return 'spreadsheet_extraction';
        }

        if ($this->preflightService->isCad($document)) {
            return 'preflight';
        }

        if ($this->preflightService->isPdf($document)) {
            return 'pdf_text_layer';
        }

        return 'ocr_request';
    }

    private function documentContent(EstimateGenerationDocument $document): string
    {
        $organization = $document->session?->organization;

        return $this->fileService->disk($organization)->get((string) $document->storage_path);
    }

    private function cadPlaceholderRecognition(EstimateGenerationDocument $document): OcrRecognitionResult
    {
        return new OcrRecognitionResult(
            provider: 'cad_metadata',
            model: 'cad_placeholder_v1',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: '',
                    confidence: 0.2,
                    languageCodes: [],
                    rawPayload: [
                        'extension' => $this->preflightService->extension($document),
                        'filename' => $document->filename,
                    ],
                ),
            ],
            rawPayload: [
                'source' => 'cad',
                'extension' => $this->preflightService->extension($document),
                'requires_geometry_pipeline' => true,
            ],
            metadata: [
                'source_format' => 'cad',
                'requires_geometry_pipeline' => true,
            ],
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function operationContext(EstimateGenerationDocument $document): AiOperationContext
    {
        $seed = implode('|', ['document', $document->session_id, $document->id, $document->source_version ?: $document->checksum_sha256, (int) $document->ocr_attempts]);
        $correlationId = AiOperationContext::deterministicId($seed);

        return new AiOperationContext(
            correlationId: $correlationId,
            attemptId: $correlationId,
            organizationId: (int) $document->organization_id,
            projectId: (int) $document->project_id,
            sessionId: (int) $document->session_id,
            stage: 'understand_documents',
            operation: 'ocr',
            attemptOrdinal: 1,
            documentId: (int) $document->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pageNormalizedPayload(OcrPageResult $page): array
    {
        $payload = [
            'blocks' => $page->blocks,
        ];

        if (is_array($page->rawPayload['geometry'] ?? null)) {
            $payload['geometry'] = $page->rawPayload['geometry'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $factsSummary
     */
    private function requiresDocumentReview(array $factsSummary): bool
    {
        $understanding = is_array($factsSummary['document_understanding'] ?? null)
            ? $factsSummary['document_understanding']
            : [];
        $capabilities = is_array($understanding['extracted_capabilities'] ?? null)
            ? $understanding['extracted_capabilities']
            : [];

        return ($understanding['role_for_estimation'] ?? null) === 'needs_review'
            || ($capabilities['requires_manual_review'] ?? false) === true;
    }

    /**
     * @param  array<int, ExtractedDocumentFact>  $facts
     * @param  array<string, mixed>  $factsSummary
     * @return array<string, mixed>
     */
    private function persistRecognition(
        EstimateGenerationDocument $document,
        OcrRecognitionResult $recognition,
        array $facts,
        array $factsSummary
    ): array {
        return DB::transaction(function () use ($document, $recognition, $facts, $factsSummary): array {
            $document->facts()->delete();
            $document->drawingElements()->delete();
            $document->quantityTakeoffs()->delete();
            $document->scopeInferences()->delete();
            $document->pages()->delete();
            $pageIds = [];
            $pageModels = [];

            foreach ($recognition->pages as $page) {
                $pageModel = EstimateGenerationDocumentPage::create([
                    'document_id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'project_id' => $document->project_id,
                    'session_id' => $document->session_id,
                    'page_number' => $page->pageNumber,
                    'width' => $page->width,
                    'height' => $page->height,
                    'rotation' => $page->rotation,
                    'language_codes' => $page->languageCodes,
                    'text' => $page->text,
                    'text_hash' => $page->text !== '' ? hash('sha256', $page->text) : null,
                    'confidence' => $page->confidence,
                    'normalized_payload' => $this->pageNormalizedPayload($page),
                    'quality_flags' => [],
                ]);

                $pageIds[$page->pageNumber] = $pageModel->id;
                $pageModels[$page->pageNumber] = $pageModel;
            }

            $drawingAnalysis = $this->drawingUnderstandingService->analyzeAndPersist($document, $recognition, $pageIds);
            $scopeInferences = $this->scopeInferenceService->persistForDocument($document, $factsSummary, $drawingAnalysis);
            $documentUnderstanding = $this->documentUnderstandingSummaryBuilder->build(
                $document,
                $recognition,
                $drawingAnalysis->summary,
                $factsSummary,
            );
            $this->persistPageUnderstanding($pageModels, $documentUnderstanding);
            $persistedFactsSummary = [
                ...$factsSummary,
                'document_understanding' => $documentUnderstanding,
                'drawing_understanding' => $drawingAnalysis->summary,
                'scope_inferences' => $scopeInferences,
            ];

            foreach ($facts as $fact) {
                $pageNumber = (int) ($fact->sourceRef['page_number'] ?? 0);

                EstimateGenerationDocumentFact::create([
                    'document_id' => $document->id,
                    'page_id' => $pageIds[$pageNumber] ?? null,
                    'organization_id' => $document->organization_id,
                    'project_id' => $document->project_id,
                    'session_id' => $document->session_id,
                    'fact_type' => $fact->factType,
                    'scope_key' => $fact->scopeKey,
                    'label' => $fact->label,
                    'value_text' => $fact->valueText,
                    'value_number' => $fact->valueNumber,
                    'unit' => $fact->unit,
                    'confidence' => $fact->confidence,
                    'source_ref' => $fact->sourceRef,
                    'normalized_payload' => $fact->normalizedPayload,
                ]);
            }

            $document->forceFill([
                'extracted_text' => $recognition->text(),
                'structured_payload' => $recognition->toArray(),
                'facts_summary' => $persistedFactsSummary,
                'meta' => [
                    ...(is_array($document->meta) ? $document->meta : []),
                    'document_understanding' => $documentUnderstanding,
                ],
                'page_count' => count($recognition->pages),
                'processed_page_count' => count($recognition->pages),
                'ocr_provider' => $recognition->provider,
                'ocr_model' => $recognition->model,
            ])->save();

            return $persistedFactsSummary;
        });
    }

    /**
     * @param  array<int, EstimateGenerationDocumentPage>  $pageModels
     * @param  array<string, mixed>  $documentUnderstanding
     */
    private function persistPageUnderstanding(array $pageModels, array $documentUnderstanding): void
    {
        $pageUnderstanding = $this->documentUnderstandingSummaryBuilder->pageUnderstandingByNumber($documentUnderstanding);

        foreach ($pageUnderstanding as $pageNumber => $understanding) {
            $pageModel = $pageModels[$pageNumber] ?? null;

            if (! $pageModel instanceof EstimateGenerationDocumentPage) {
                continue;
            }

            $payload = is_array($pageModel->normalized_payload) ? $pageModel->normalized_payload : [];
            $pageModel->forceFill([
                'normalized_payload' => [
                    ...$payload,
                    'page_role' => $understanding['page_role'],
                    'page_understanding' => $understanding,
                ],
            ])->save();
        }
    }
}
